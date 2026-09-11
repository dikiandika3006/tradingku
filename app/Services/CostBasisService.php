<?php

namespace App\Services;

use App\Models\Trade;
use Carbon\Carbon;

/**
 * Perhitungan modal (cost basis) untuk sisa posisi sebuah aset.
 *
 * Memakai metode rata-rata berjalan (running average cost): setiap penjualan
 * mengurangi kolam biaya secara proporsional, sehingga posisi yang sempat
 * ditutup habis memulai rata-rata dari nol lagi.
 *
 * Ini menggantikan cara lama yang merata-ratakan SELURUH transaksi beli tanpa
 * memperhatikan penjualan — cara itu membuat harga beli lama ikut menyeret
 * rata-rata posisi yang sebenarnya sudah tutup lalu dibuka kembali.
 */
class CostBasisService
{
    /** Mata uang quote yang perlu dikonversi ke USDT. */
    protected const IDR_QUOTES = ['IDR', 'IDRT', 'BIDR'];

    /** Menambah posisi. */
    protected const INFLOW = ['BUY', 'DEPOSIT'];

    /** Mengurangi posisi. */
    protected const OUTFLOW = ['SELL', 'WITHDRAW'];

    /**
     * Hitung modal sisa posisi sebuah aset.
     *
     * @return array{amount: float, cost_usdt: float, avg_price_usdt: float, opened_at: \Carbon\Carbon|null}
     */
    public function forAsset(string $asset, array $prices, float $fallbackRate = 16400.0): array
    {
        $trades = Trade::forBaseAsset($asset)
            ->orderBy('trade_time', 'asc')
            ->get();

        $amount   = 0.0;
        $cost     = 0.0;
        $openedAt = null;

        foreach ($trades as $trade) {
            $type = strtoupper(trim($trade->type));
            $qty  = (float)$trade->amount;

            [, $quote] = Trade::splitSymbol($trade->symbol);
            $priceUsdt = $this->toUsdt((float)$trade->price, $quote, $prices, $fallbackRate);

            if (in_array($type, self::INFLOW, true)) {
                // Posisi dibuka kembali setelah sempat kosong
                if ($amount <= 0.00000001) {
                    $openedAt = $trade->trade_time ? Carbon::parse($trade->trade_time) : null;
                }

                $amount += $qty;
                $cost   += $priceUsdt * $qty;
            } elseif (in_array($type, self::OUTFLOW, true)) {
                // Rata-rata tidak berubah saat menjual; kolam biaya menyusut sebanding
                $avg    = $amount > 0 ? $cost / $amount : 0.0;
                $amount = max(0.0, $amount - $qty);
                $cost   = $amount * $avg;

                if ($amount <= 0.00000001) {
                    $openedAt = null;
                }
            }
        }

        return [
            'amount'         => $amount,
            'cost_usdt'      => $cost,
            'avg_price_usdt' => $amount > 0 ? $cost / $amount : 0.0,
            'opened_at'      => $openedAt,
        ];
    }

    protected function toUsdt(float $price, ?string $quote, array $prices, float $fallbackRate): float
    {
        if (!in_array(strtoupper((string)$quote), self::IDR_QUOTES, true)) {
            return $price;
        }

        $rate = $prices['USDTIDR']
            ?? ($prices['USDT' . strtoupper((string)$quote)]
            ?? ($prices['USDTIDRT'] ?? $fallbackRate));

        return $rate > 100 ? ($price / $rate) : ($price / $fallbackRate);
    }
}
