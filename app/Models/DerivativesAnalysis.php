<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DerivativesAnalysis extends Model
{
    protected $table = 'derivatives_analyses';

    protected $fillable = [
        'symbol',
        'funding_rate',
        'ls_ratio_volume',
        'ls_ratio_accounts',
        'ls_ratio_top_traders',
        'open_interest_change',
        'volume_change',
        'price_change',
        'liq_long_24h',
        'liq_short_24h',
        'signal',
        'bottom_score',
        'notes',
    ];

    /**
     * Calculate Long % from L/S Ratio (Volume).
     * Formula: Long% = Ratio / (1 + Ratio) * 100
     */
    public function getLongPercentVolumeAttribute()
    {
        $ratio = (float) $this->ls_ratio_volume;
        if ($ratio <= 0) return 0;
        return round($ratio / (1 + $ratio) * 100, 1);
    }

    /**
     * Calculate Short % from L/S Ratio (Volume).
     * Formula: Short% = 1 / (1 + Ratio) * 100
     */
    public function getShortPercentVolumeAttribute()
    {
        $ratio = (float) $this->ls_ratio_volume;
        if ($ratio <= 0) return 100;
        return round(1 / (1 + $ratio) * 100, 1);
    }

    /**
     * Calculate Long % from L/S Ratio (Accounts).
     */
    public function getLongPercentAccountsAttribute()
    {
        $ratio = (float) $this->ls_ratio_accounts;
        if (!$ratio || $ratio <= 0) return null;
        return round($ratio / (1 + $ratio) * 100, 1);
    }

    /**
     * Calculate Short % from L/S Ratio (Accounts).
     */
    public function getShortPercentAccountsAttribute()
    {
        $ratio = (float) $this->ls_ratio_accounts;
        if (!$ratio || $ratio <= 0) return null;
        return round(1 / (1 + $ratio) * 100, 1);
    }

    /**
     * Calculate Long % from L/S Ratio (Top Traders).
     */
    public function getLongPercentTopTradersAttribute()
    {
        $ratio = (float) $this->ls_ratio_top_traders;
        if (!$ratio || $ratio <= 0) return null;
        return round($ratio / (1 + $ratio) * 100, 1);
    }

    /**
     * Get total liquidation amount.
     */
    public function getTotalLiquidationAttribute()
    {
        return (float) $this->liq_long_24h + (float) $this->liq_short_24h;
    }

    /**
     * Get liquidation long percentage.
     */
    public function getLiqLongPercentAttribute()
    {
        $total = $this->total_liquidation;
        if ($total <= 0) return 0;
        return round((float) $this->liq_long_24h / $total * 100, 1);
    }

    /**
     * Get signal with emoji label.
     */
    public function getSignalLabelAttribute()
    {
        return match ($this->signal) {
            'STRONG_BEARISH' => '🔴🔴🔴 STRONG BEARISH',
            'BEARISH' => '🔴 BEARISH',
            'NEUTRAL' => '🟡 NETRAL',
            'BULLISH' => '🟢 BULLISH',
            'STRONG_BULLISH' => '🟢🟢🟢 STRONG BULLISH',
            default => '⚪ UNKNOWN',
        };
    }

    /**
     * Get signal CSS class.
     */
    public function getSignalClassAttribute()
    {
        return match ($this->signal) {
            'STRONG_BEARISH', 'BEARISH' => 'badge-sell',
            'BULLISH', 'STRONG_BULLISH' => 'badge-buy',
            default => 'badge-neutral',
        };
    }

    /**
     * Get the 5-point bottom checklist with status.
     */
    public function getBottomChecklistAttribute()
    {
        $checklist = [];

        // 1. Liquidation long mereda (< $50K)
        $liqLong = (float) $this->liq_long_24h;
        $checklist[] = [
            'label' => 'Liquidation long mereda (< $50K)',
            'status' => $liqLong < 50000 ? 'yes' : ($liqLong < 200000 ? 'pending' : 'no'),
            'value' => '$' . number_format($liqLong, 0),
        ];

        // 2. Funding Rate negatif
        $fr = (float) $this->funding_rate;
        $checklist[] = [
            'label' => 'Funding Rate menjadi negatif',
            'status' => $fr < 0 ? 'yes' : ($fr < 0.01 ? 'pending' : 'no'),
            'value' => number_format($fr, 6),
        ];

        // 3. OI turun + Harga turun (long menyerah)
        $oiDown = (float) $this->open_interest_change < 0;
        $priceDown = (float) $this->price_change < 0;
        $checklist[] = [
            'label' => 'OI turun + Harga turun (long menyerah)',
            'status' => ($oiDown && $priceDown) ? 'yes' : 'no',
            'value' => 'OI: ' . number_format($this->open_interest_change, 2) . '%, Price: ' . number_format($this->price_change, 2) . '%',
        ];

        // 4. L/S Ratio volume > 0.7 (tidak extreme short)
        $ratio = (float) $this->ls_ratio_volume;
        $checklist[] = [
            'label' => 'L/S Ratio volume > 0.7 (short tidak extreme)',
            'status' => $ratio > 0.7 ? 'yes' : ($ratio > 0.3 ? 'pending' : 'no'),
            'value' => number_format($ratio, 4),
        ];

        // 5. Volume turun + Harga sideway (selling exhaustion)
        $volDown = (float) $this->volume_change < 50;
        $priceSideway = abs((float) $this->price_change) < 2;
        $checklist[] = [
            'label' => 'Volume turun + Harga sideway (selling exhaustion)',
            'status' => ($volDown && $priceSideway) ? 'yes' : ($volDown || $priceSideway ? 'pending' : 'no'),
            'value' => 'Vol: ' . number_format($this->volume_change, 2) . '%, Price: ' . number_format($this->price_change, 2) . '%',
        ];

        return $checklist;
    }

    /**
     * Auto-calculate signal based on derivatives data.
     */
    public static function calculateSignal(array $data): string
    {
        $score = 0; // positive = bullish, negative = bearish

        $fr = (float) ($data['funding_rate'] ?? 0);
        $lsRatio = (float) ($data['ls_ratio_volume'] ?? 1);
        $oiChange = (float) ($data['open_interest_change'] ?? 0);
        $volChange = (float) ($data['volume_change'] ?? 0);
        $priceChange = (float) ($data['price_change'] ?? 0);
        $liqLong = (float) ($data['liq_long_24h'] ?? 0);
        $liqShort = (float) ($data['liq_short_24h'] ?? 0);

        // Factor 1: Funding Rate
        if ($fr > 0.05) $score -= 2; // Very positive FR = bearish
        elseif ($fr > 0.01) $score -= 1;
        elseif ($fr < -0.05) $score += 2; // Negative FR = bullish
        elseif ($fr < 0) $score += 1;

        // Factor 2: L/S Ratio Volume
        if ($lsRatio < 0.3) $score -= 2; // Extreme short dominance
        elseif ($lsRatio < 0.7) $score -= 1;
        elseif ($lsRatio > 2.0) $score -= 1; // Crowded long = contrarian bearish
        elseif ($lsRatio > 1.5) $score += 0; // Neutral-ish

        // Factor 3: OI + Price combination
        if ($oiChange > 0 && $priceChange < -3) $score -= 2; // OI up + price down = strong bearish
        elseif ($oiChange > 0 && $priceChange > 3) $score += 2; // OI up + price up = strong bullish
        elseif ($oiChange < 0 && $priceChange < 0) $score += 1; // OI down + price down = capitulation (slightly bullish)

        // Factor 4: Volume + Price
        if ($volChange > 100 && $priceChange < -5) $score -= 2; // High vol dump
        elseif ($volChange > 100 && $priceChange > 5) $score += 2; // High vol pump
        elseif ($volChange < 50 && abs($priceChange) < 2) $score += 1; // Low vol sideway = exhaustion

        // Factor 5: Liquidation dominance
        $totalLiq = $liqLong + $liqShort;
        if ($totalLiq > 0) {
            $liqLongPct = $liqLong / $totalLiq * 100;
            if ($liqLongPct > 90) $score -= 1; // Longs getting rekt
            elseif ($liqLongPct < 10) $score += 1; // Shorts getting rekt
        }

        // Convert score to signal
        if ($score <= -4) return 'STRONG_BEARISH';
        if ($score <= -2) return 'BEARISH';
        if ($score >= 4) return 'STRONG_BULLISH';
        if ($score >= 2) return 'BULLISH';
        return 'NEUTRAL';
    }

    /**
     * Auto-calculate bottom score (0-5).
     */
    public static function calculateBottomScore(array $data): int
    {
        $score = 0;

        // 1. Liquidation long mereda
        if ((float) ($data['liq_long_24h'] ?? 0) < 50000) $score++;

        // 2. Funding rate negatif
        if ((float) ($data['funding_rate'] ?? 0) < 0) $score++;

        // 3. OI turun + harga turun
        if ((float) ($data['open_interest_change'] ?? 0) < 0 && (float) ($data['price_change'] ?? 0) < 0) $score++;

        // 4. L/S ratio > 0.7
        if ((float) ($data['ls_ratio_volume'] ?? 0) > 0.7) $score++;

        // 5. Volume turun + harga sideway
        if ((float) ($data['volume_change'] ?? 0) < 50 && abs((float) ($data['price_change'] ?? 0)) < 2) $score++;

        return $score;
    }
}
