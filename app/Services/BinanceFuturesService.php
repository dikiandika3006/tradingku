<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BinanceFuturesService
{
    protected $baseUrl = 'https://fapi.binance.com';

    /**
     * Fetch all derivatives data for a symbol in one call.
     * Returns: funding_rate, ls_ratio_volume (from L/S account), ls_ratio_accounts,
     *          ls_ratio_top_traders, open_interest_change, volume_change, price_change
     */
    public function getDerivativesData(string $symbol): ?array
    {
        $symbol = strtoupper(trim($symbol));

        // Ensure symbol ends with USDT
        if (!str_ends_with($symbol, 'USDT')) {
            $symbol .= 'USDT';
        }

        try {
            // 1. Premium Index (funding rate + mark price)
            $premiumIndex = $this->fetchPremiumIndex($symbol);

            // 2. 24h Ticker (price change %, volume)
            $ticker = $this->fetch24hTicker($symbol);

            // 3. Open Interest History (to calculate OI change %)
            $oiChange = $this->fetchOIChange($symbol);

            // 4. Global Long/Short Account Ratio
            $globalLS = $this->fetchGlobalLongShortRatio($symbol);

            // 5. Top Trader Long/Short Position Ratio
            $topTraderLS = $this->fetchTopTraderLongShortRatio($symbol);

            // 6. Top Trader Long/Short Account Ratio
            $topTraderAccountLS = $this->fetchTopTraderAccountRatio($symbol);

            if (!$premiumIndex || !$ticker) {
                return null;
            }

            return [
                'symbol' => str_replace('USDT', '', $symbol),
                'funding_rate' => $premiumIndex['lastFundingRate'] ?? null,
                'ls_ratio_accounts' => $globalLS['longShortRatio'] ?? null,
                'ls_ratio_top_traders' => $topTraderLS['longShortRatio'] ?? null,
                'ls_ratio_volume' => $topTraderAccountLS['longShortRatio'] ?? ($globalLS['longShortRatio'] ?? null),
                'open_interest_change' => $oiChange,
                'volume_change' => $this->calculateVolumeChange($symbol),
                'price_change' => (float) ($ticker['priceChangePercent'] ?? 0),
                'current_price' => (float) ($ticker['lastPrice'] ?? 0),
                'open_interest_value' => (float) ($premiumIndex['openInterest'] ?? 0),
            ];
        } catch (\Exception $e) {
            Log::error("BinanceFuturesService error for {$symbol}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get premium index (funding rate, mark price).
     */
    protected function fetchPremiumIndex(string $symbol): ?array
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/fapi/v1/premiumIndex', [
                'symbol' => $symbol,
            ]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::warning("fetchPremiumIndex failed: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Get 24h ticker data (price change %, volume).
     */
    protected function fetch24hTicker(string $symbol): ?array
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/fapi/v1/ticker/24hr', [
                'symbol' => $symbol,
            ]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::warning("fetch24hTicker failed: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Calculate Open Interest change % (compare latest to 24h ago).
     */
    protected function fetchOIChange(string $symbol): float
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/futures/data/openInterestHist', [
                'symbol' => $symbol,
                'period' => '1h',
                'limit' => 25, // ~24 hours of data
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (count($data) >= 2) {
                    $latest = (float) end($data)['sumOpenInterestValue'];
                    $oldest = (float) reset($data)['sumOpenInterestValue'];

                    if ($oldest > 0) {
                        return round(($latest - $oldest) / $oldest * 100, 2);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("fetchOIChange failed: " . $e->getMessage());
        }
        return 0;
    }

    /**
     * Calculate Volume change % (compare today's volume to yesterday's).
     */
    protected function calculateVolumeChange(string $symbol): float
    {
        try {
            // Get 2 daily klines to compare volume
            $response = Http::timeout(5)->get($this->baseUrl . '/fapi/v1/klines', [
                'symbol' => $symbol,
                'interval' => '1d',
                'limit' => 2,
            ]);

            if ($response->successful()) {
                $klines = $response->json();
                if (count($klines) >= 2) {
                    $yesterdayVol = (float) $klines[0][7]; // Quote asset volume (USDT)
                    $todayVol = (float) $klines[1][7];

                    if ($yesterdayVol > 0) {
                        return round(($todayVol - $yesterdayVol) / $yesterdayVol * 100, 2);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("calculateVolumeChange failed: " . $e->getMessage());
        }
        return 0;
    }

    /**
     * Get Global Long/Short Account Ratio.
     */
    protected function fetchGlobalLongShortRatio(string $symbol): ?array
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/futures/data/globalLongShortAccountRatio', [
                'symbol' => $symbol,
                'period' => '1h',
                'limit' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return !empty($data) ? $data[0] : null;
            }
        } catch (\Exception $e) {
            Log::warning("fetchGlobalLongShortRatio failed: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Get Top Trader Long/Short Position Ratio.
     */
    protected function fetchTopTraderLongShortRatio(string $symbol): ?array
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/futures/data/topLongShortPositionRatio', [
                'symbol' => $symbol,
                'period' => '1h',
                'limit' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return !empty($data) ? $data[0] : null;
            }
        } catch (\Exception $e) {
            Log::warning("fetchTopTraderLongShortRatio failed: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Get Top Trader Long/Short Account Ratio.
     */
    protected function fetchTopTraderAccountRatio(string $symbol): ?array
    {
        try {
            $response = Http::timeout(5)->get($this->baseUrl . '/futures/data/topLongShortAccountRatio', [
                'symbol' => $symbol,
                'period' => '1h',
                'limit' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return !empty($data) ? $data[0] : null;
            }
        } catch (\Exception $e) {
            Log::warning("fetchTopTraderAccountRatio failed: " . $e->getMessage());
        }
        return null;
    }
}
