<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Trade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class TokocryptoService
{
    protected $baseUrl = 'https://www.tokocrypto.site';

    /**
     * API privat resmi Tokocrypto. Endpoint gaya Binance (/api/v3/account di
     * tokocrypto.site) menolak API key Tokocrypto dengan kode -2015, jadi data
     * akun dan order harus lewat sini.
     */
    protected const OPEN_API_URL = 'https://www.tokocrypto.com/open/v1';

    /** Kode numerik Open API -> label yang dipakai tampilan. */
    protected const ORDER_SIDE = [0 => 'BUY', 1 => 'SELL'];
    protected const ORDER_TYPE = [
        1 => 'LIMIT', 2 => 'MARKET', 3 => 'STOP_LOSS', 4 => 'STOP_LOSS_LIMIT',
        5 => 'TAKE_PROFIT', 6 => 'TAKE_PROFIT_LIMIT', 7 => 'LIMIT_MAKER',
    ];
    protected const ORDER_STATUS = [
        0 => 'NEW', 1 => 'PARTIALLY_FILLED', 2 => 'FILLED', 3 => 'CANCELED',
        4 => 'PENDING_CANCEL', 5 => 'REJECTED', 6 => 'EXPIRED',
    ];

    /**
     * Retrieve the API Key from database or .env fallback.
     */
    public function getApiKey(): string
    {
        $setting = Setting::where('key', 'tokocrypto_api_key')->first();
        if ($setting && !empty($setting->value)) {
            return $setting->value;
        }
        return config('services.tokocrypto.api_key', env('TOKOCRYPTO_API_KEY', ''));
    }

    /**
     * Retrieve the API Secret from database.
     */
    public function getApiSecret(): string
    {
        $setting = Setting::where('key', 'tokocrypto_api_secret')->first();
        if ($setting && !empty($setting->value)) {
            return $setting->value;
        }
        return env('TOKOCRYPTO_API_SECRET', '');
    }

    /**
     * Check if API credentials are fully configured.
     */
    public function hasCredentials(): bool
    {
        return !empty($this->getApiKey()) && !empty($this->getApiSecret());
    }

    /**
     * Fetch current market prices for symbols.
     * Caches all prices for 10 seconds to avoid rate limiting.
     */
    public function getAllPrices(): array
    {
        $cached = Cache::get('tokocrypto_market_prices');
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        // Tokocrypto dulu (punya pasangan IDR), lalu Binance. Tiap sumber dicoba
        // sendiri-sendiri: timeout di sumber pertama tidak boleh membatalkan
        // cadangannya.
        $sources = [
            $this->baseUrl . '/api/v3/ticker/price',
            'https://api.binance.com/api/v3/ticker/price',
        ];

        foreach ($sources as $url) {
            try {
                $response = Http::timeout(3)->get($url);
                $data = $response->successful() ? $response->json() : null;

                if (is_array($data) && $data !== []) {
                    $prices = [];
                    foreach ($data as $item) {
                        if (isset($item['symbol'], $item['price'])) {
                            $prices[$item['symbol']] = (float)$item['price'];
                        }
                    }

                    if ($prices !== []) {
                        Cache::put('tokocrypto_market_prices', $prices, 10);
                        return $prices;
                    }
                }

                \Log::warning("Harga pasar gagal dari {$url}: HTTP " . $response->status());
            } catch (\Exception $e) {
                \Log::warning("Harga pasar gagal dari {$url}: " . $e->getMessage());
            }
        }

        // Hasil kosong sengaja tidak di-cache supaya permintaan berikutnya
        // langsung mencoba lagi.
        return [];
    }

    /**
     * Fetch price of a single symbol.
     */
    public function getPrice(string $symbol): float
    {
        $prices = $this->getAllPrices();
        $symbol = strtoupper(trim($symbol));
        return $prices[$symbol] ?? 0.0;
    }

    /**
     * Fetch user spot account balances.
     * Returns an array of assets with balances and current valuations.
     */
    public function getPortfolio(): array
    {
        $balances = [];
        $fetchedFromApi = false;

        // Saldo asli dari bursa hanya bila diaktifkan (TOKOCRYPTO_PORTFOLIO_FROM_API).
        // Bawaannya mati: saldo dihitung dari jurnal, dan API cukup dipakai
        // halaman Antrean Pesanan. Disimpan sebentar karena satu halaman bisa
        // memanggil getPortfolio() beberapa kali.
        $accountAssets = $this->hasCredentials() && config('services.tokocrypto.portfolio_from_api', false)
            ? Cache::remember('tokocrypto_account_assets_' . md5($this->getApiKey()), 15, function () {
                return $this->openApiGet('/account/spot')['accountAssets'] ?? null;
            })
            : null;

        if (is_array($accountAssets)) {
            foreach ($accountAssets as $b) {
                $free = (float)($b['free'] ?? 0);
                $locked = (float)($b['locked'] ?? 0);
                $total = $free + $locked;

                if ($total > 0.00001) {
                    $balances[$b['asset']] = [
                        'asset' => $b['asset'],
                        'free' => $free,
                        'locked' => $locked,
                        'total' => $total,
                        'source' => 'API'
                    ];
                }
            }
            $fetchedFromApi = true;
        }

        // Fallback: If not fetched from API, construct balance from trade logs in database
        if (!$fetchedFromApi) {
            foreach ($this->holdingsFromJournal() as $asset => $amount) {
                if (abs($amount) > 0.00001) {
                    $balances[$asset] = [
                        'asset' => $asset,
                        'free' => $amount,
                        'locked' => 0.0,
                        'total' => $amount,
                        'source' => 'Database Logs (No API Secret)'
                    ];
                }
            }
        }

        // Add valuations (convert to USDT, etc.)
        $prices = $this->getAllPrices();
        $portfolioData = [];

        // Add IDR estimation (assume 1 USDT = 16,400 IDR or live rate if available)
        $usdtIdr = $prices['USDTIDR'] ?? ($prices['USDTBIDR'] ?? ($prices['USDTIDRT'] ?? 16400.0));
        if ($usdtIdr < 1000) {
            $usdtIdr = 16400.0; // fallback safety
        }

        $totalValuationUsdt = 0.0;

        foreach ($balances as $asset => $data) {
            $symbol = $asset . 'USDT';

            // If asset is USDT itself
            if ($asset === 'USDT') {
                $price = 1.0;
            } elseif ($fetchedFromApi && in_array($asset, ['IDR', 'BIDR', 'IDRT'], true)) {
                // Kas rupiah dari saldo bursa: tidak punya pasangan XXXUSDT, jadi
                // dinilai pakai kurs. Mode jurnal tetap seperti dulu (tidak
                // dinilai), karena saldo IDR jurnal belum tentu mencerminkan kas.
                $price = $data['total'] > 0 ? 1 / $usdtIdr : 0.0;
            } else {
                // Check if price is in prices list
                $price = $prices[$symbol] ?? 0.0;

                // Fallback: if we only have BIDR or IDRT pairs (common on Tokocrypto)
                if ($price === 0.0) {
                    $bidrPrice = $prices[$asset . 'BIDR'] ?? 0.0;
                    $usdtBidr = $prices['USDTIDR'] ?? ($prices['USDTBIDR'] ?? ($prices['USDTIDRT'] ?? 15000.0));
                    if ($bidrPrice > 0 && $usdtBidr > 0) {
                        $price = $bidrPrice / $usdtBidr;
                    }
                }
            }

            $valueUsdt = $data['total'] * $price;
            $totalValuationUsdt += $valueUsdt;

            $portfolioData[] = array_merge($data, [
                'price' => $price,
                'value_usdt' => $valueUsdt,
            ]);
        }

        // Sort by valuation descending
        usort($portfolioData, function ($a, $b) {
            return $b['value_usdt'] <=> $a['value_usdt'];
        });

        return [
            'assets' => $portfolioData,
            'total_usdt' => $totalValuationUsdt,
            'total_idr' => $totalValuationUsdt * $usdtIdr,
            'usdt_idr_rate' => $usdtIdr,
            'is_live' => $fetchedFromApi
        ];
    }

    /**
     * Saldo per aset hasil rekonstruksi jurnal trade (DEPOSIT/WITHDRAW/BUY/SELL).
     *
     * @param Carbon|null $until Hanya transaksi sampai waktu ini; null = seluruh jurnal.
     * @param int[] $excludeIds Transaksi yang diabaikan (mis. yang sedang diedit di form SELL).
     * @return array<string, float>
     */
    public function holdingsFromJournal(?Carbon $until = null, array $excludeIds = []): array
    {
        $trades = Trade::orderBy('trade_time', 'asc')
            ->when($until, fn ($q) => $q->where('trade_time', '<=', $until))
            ->when($excludeIds, fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->get();
        $holdings = [];

        foreach ($trades as $trade) {
            $symbol = strtoupper(trim($trade->symbol));
            $type = strtoupper(trim($trade->type));
            $amount = (float)$trade->amount;
            $price = (float)$trade->price;
            $total = (float)$trade->total;
            if ($total <= 0) {
                $total = $price * $amount;
            }

            // Simbol dipecah dengan cara yang sama untuk SEMUA tipe transaksi,
            // supaya setoran bersimbol pasangan (mis. "USDTIDR") menambah saldo
            // aset dasarnya (USDT) dan bukan menciptakan aset fiktif "USDTIDR".
            [$asset, $quote] = Trade::splitSymbol($symbol);

            if ($type === 'DEPOSIT' || $type === 'WITHDRAW') {
                // Beda dengan BUY/SELL: sisi quote TIDAK disentuh. Dana setoran
                // berasal dari luar portofolio, jadi mendebit IDR di sini akan
                // memunculkan saldo fiat negatif yang tidak pernah ada.
                $sign = ($type === 'DEPOSIT') ? 1 : -1;
                $holdings[$asset] = ($holdings[$asset] ?? 0.0) + ($sign * $amount);
            } elseif ($type === 'BUY') {
                $holdings[$asset] = ($holdings[$asset] ?? 0.0) + $amount;
                if ($quote) {
                    $holdings[$quote] = ($holdings[$quote] ?? 0.0) - $total;
                }
            } elseif ($type === 'SELL') {
                $holdings[$asset] = ($holdings[$asset] ?? 0.0) - $amount;
                if ($quote) {
                    $holdings[$quote] = ($holdings[$quote] ?? 0.0) + $total;
                }
            }
        }

        return $holdings;
    }

    /**
     * Fetch user open orders from Tokocrypto.
     */
    public function getOpenOrders(): array
    {
        return $this->fetchOpenOrders() ?? [];
    }

    /**
     * Sama dengan getOpenOrders(), tetapi null bila API gagal, supaya halaman
     * bisa membedakan "tidak ada order" dari "gagal mengambil order".
     */
    public function fetchOpenOrders(): ?array
    {
        if (!$this->hasCredentials()) {
            return null;
        }

        // type=1 = order yang masih terbuka (type=2 riwayat)
        $data = $this->openApiGet('/orders', ['type' => 1, 'limit' => 1000]);
        if ($data === null) {
            return null;
        }
        $list = $data['list'] ?? [];

        // Samakan dengan format gaya Binance yang dipakai halaman Open Orders
        return array_map(fn(array $o) => [
            'orderId' => $o['orderId'] ?? null,
            'symbol' => str_replace('_', '', (string)($o['symbol'] ?? '')),
            'side' => self::ORDER_SIDE[$o['side'] ?? -1] ?? (string)($o['side'] ?? ''),
            'type' => self::ORDER_TYPE[$o['type'] ?? -1] ?? (string)($o['type'] ?? ''),
            'status' => self::ORDER_STATUS[$o['status'] ?? -1] ?? (string)($o['status'] ?? ''),
            'price' => (string)($o['price'] ?? '0'),
            'origQty' => (string)($o['origQty'] ?? '0'),
            'executedQty' => (string)($o['executedQty'] ?? '0'),
            'stopPrice' => (string)($o['stopPrice'] ?? '0'),
            'time' => (int)($o['createTime'] ?? $o['time'] ?? 0),
        ], array_values(array_filter($list, 'is_array')));
    }

    /**
     * GET bertanda tangan ke Open API Tokocrypto.
     *
     * Mengembalikan isi "data" bila code = 0, selain itu null (dan dicatat ke
     * log tanpa menyertakan key/secret).
     */
    protected function openApiGet(string $path, array $params = [], int $timeout = 6): ?array
    {
        try {
            $query = http_build_query($params + [
                'timestamp' => round(microtime(true) * 1000),
                'recvWindow' => 5000,
            ]);
            $signature = hash_hmac('sha256', $query, $this->getApiSecret());

            $response = Http::timeout($timeout)
                ->withHeaders(['X-MBX-APIKEY' => $this->getApiKey()])
                ->get(self::OPEN_API_URL . $path . '?' . $query . '&signature=' . $signature);

            $json = $response->json();
            if ($response->successful() && is_array($json) && ($json['code'] ?? null) === 0) {
                return is_array($json['data'] ?? null) ? $json['data'] : [];
            }

            \Log::warning("Tokocrypto Open API {$path} gagal", [
                'http' => $response->status(),
                'code' => $json['code'] ?? null,
                'msg' => $json['msg'] ?? null,
            ]);
        } catch (\Exception $e) {
            \Log::error("Tokocrypto Open API {$path} error: " . $e->getMessage());
        }

        return null;
    }
}
