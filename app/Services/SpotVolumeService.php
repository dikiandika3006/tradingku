<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Volume spot terakumulasi dari beberapa bursa besar, langsung dari API publik
 * masing-masing bursa.
 *
 * Tiap bursa dimintai candle 1 jam untuk 7 hari terakhir, lalu volumenya
 * dijumlahkan ke dalam beberapa jendela waktu (1 jam, 4 jam, 12 jam, 24 jam,
 * 7 hari). Semua dinormalkan ke volume dalam USD.
 *
 * Candle jam berjalan SELALU dibuang karena belum lengkap — memasukkannya
 * membuat angka 1 jam naik-turun sepanjang jam dan merusak perbandingan
 * antar hari.
 */
class SpotVolumeService
{
    /** Jendela waktu dalam satuan candle 1 jam. */
    public const WINDOWS = ['1h' => 1, '4h' => 4, '12h' => 12, '24h' => 24, '1w' => 168];

    /** Berapa candle yang perlu diambil: jendela terpanjang + 1 untuk candle berjalan. */
    protected const CANDLES_NEEDED = 169;

    /** Pasangan yang dipakai di tiap bursa, per aset. */
    protected const PAIRS = [
        'BTC' => [
            'Binance'  => 'BTCUSDT',
            'Bybit'    => 'BTCUSDT',
            'OKX'      => 'BTC-USDT',
            'Kraken'   => 'XBTUSDT',
            'Coinbase' => 'BTC-USD',
        ],
    ];

    protected const CACHE_SECONDS = 120;

    /** Aset yang didukung saat ini. */
    public function supportedSymbols(): array
    {
        return array_keys(self::PAIRS);
    }

    /**
     * Ambil volume per bursa beserta akumulasinya.
     *
     * @return array{ok: bool, message: string|null, exchanges: array<int, array>,
     *               totals: array<string, float>, price_usd: float|null,
     *               failures: array<int, string>, fetched_at: \Carbon\Carbon|null}
     */
    public function aggregate(string $symbol = 'BTC', bool $useCache = true): array
    {
        $symbol = strtoupper(trim($symbol));

        if (!isset(self::PAIRS[$symbol])) {
            return $this->failure("Aset {$symbol} belum didukung. Tersedia: "
                . implode(', ', $this->supportedSymbols()));
        }

        $cacheKey = "spot_volume_aggregate_{$symbol}";

        if ($useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $exchanges = [];
        $failures  = [];
        $prices    = [];

        foreach (self::PAIRS[$symbol] as $bursa => $pair) {
            $metode = 'fetch' . $bursa;

            try {
                $candles = $this->$metode($pair);
            } catch (\Throwable $e) {
                Log::warning("Gagal mengambil candle {$bursa}: " . $e->getMessage());
                $failures[] = "{$bursa}: " . $e->getMessage();
                continue;
            }

            if (empty($candles['volumes'])) {
                $failures[] = "{$bursa}: tidak ada data candle.";
                continue;
            }

            $baris = [
                'exchange_name' => $bursa,
                'pair'          => $pair,
                'price_usd'     => $candles['price'],
                'candle_count'  => count($candles['volumes']),
            ];

            foreach (self::WINDOWS as $tf => $jam) {
                $baris["volume_usd_{$tf}"] = $this->jumlahkan($candles['volumes'], $jam);
            }

            if ($candles['price'] !== null) {
                $prices[] = $candles['price'];
            }

            $exchanges[] = $baris;
        }

        if (empty($exchanges)) {
            return $this->failure('Tidak ada bursa yang bisa dihubungi. ' . implode(' | ', $failures));
        }

        // Volume terbesar di atas
        usort($exchanges, fn($a, $b) => ($b['volume_usd_24h'] ?? 0) <=> ($a['volume_usd_24h'] ?? 0));

        $totals = [];
        foreach (array_keys(self::WINDOWS) as $tf) {
            $totals[$tf] = array_sum(array_column($exchanges, "volume_usd_{$tf}"));
        }

        $hasil = [
            'ok'         => true,
            'message'    => null,
            'exchanges'  => $exchanges,
            'totals'     => $totals,
            // Harga acuan: rata-rata bursa yang berhasil, cukup untuk konteks
            'price_usd'  => $prices ? array_sum($prices) / count($prices) : null,
            'failures'   => $failures,
            'fetched_at' => now(),
        ];

        Cache::put($cacheKey, $hasil, self::CACHE_SECONDS);

        return $hasil;
    }

    /**
     * Jumlahkan N candle terbaru.
     *
     * @param array<int, float> $volumes Urut dari yang terbaru, candle berjalan sudah dibuang.
     */
    protected function jumlahkan(array $volumes, int $jam): float
    {
        return (float)array_sum(array_slice($volumes, 0, $jam));
    }

    // ------------------------------------------------------------------
    // Adapter per bursa. Masing-masing mengembalikan:
    //   volumes = volume USD per jam, URUT DARI TERBARU, tanpa candle berjalan
    //   price   = harga penutupan candle lengkap terakhir
    // ------------------------------------------------------------------

    /** Binance: [openTime, o, h, l, c, volBase, closeTime, volQuote, ...], terlama dulu. */
    protected function fetchBinance(string $pair): array
    {
        $data = $this->json('https://api.binance.com/api/v3/klines', [
            'symbol'   => $pair,
            'interval' => '1h',
            'limit'    => self::CANDLES_NEEDED,
        ]);

        $rows = array_reverse($data ?? []);   // jadi terbaru dulu

        return $this->rapikan($rows, fn($r) => (float)($r[7] ?? 0), fn($r) => (float)($r[4] ?? 0));
    }

    /** Bybit: result.list [start, o, h, l, c, volBase, turnover], terbaru dulu. */
    protected function fetchBybit(string $pair): array
    {
        $data = $this->json('https://api.bybit.com/v5/market/kline', [
            'category' => 'spot',
            'symbol'   => $pair,
            'interval' => '60',
            'limit'    => self::CANDLES_NEEDED,
        ]);

        $rows = $data['result']['list'] ?? [];

        return $this->rapikan($rows, fn($r) => (float)($r[6] ?? 0), fn($r) => (float)($r[4] ?? 0));
    }

    /** OKX: data [ts, o, h, l, c, vol, volCcy, volCcyQuote, confirm], terbaru dulu. */
    protected function fetchOkx(string $pair): array
    {
        $data = $this->json('https://www.okx.com/api/v5/market/candles', [
            'instId' => $pair,
            'bar'    => '1H',
            'limit'  => 300,
        ]);

        $rows = $data['data'] ?? [];

        return $this->rapikan($rows, fn($r) => (float)($r[7] ?? $r[6] ?? 0), fn($r) => (float)($r[4] ?? 0));
    }

    /**
     * Kraken: result.<pair> [time, o, h, l, c, vwap, volBase, count], terlama dulu.
     * Hanya memberi volume dalam BTC, jadi dikalikan vwap agar jadi USD.
     */
    protected function fetchKraken(string $pair): array
    {
        $data = $this->json('https://api.kraken.com/0/public/OHLC', [
            'pair'     => $pair,
            'interval' => 60,
        ]);

        $result = $data['result'] ?? [];
        unset($result['last']);

        $rows = array_reverse(reset($result) ?: []);

        return $this->rapikan(
            $rows,
            function ($r) {
                $vwap = (float)($r[5] ?? 0);
                $vol  = (float)($r[6] ?? 0);
                // vwap lebih tepat daripada harga penutupan untuk volume sejam penuh
                $harga = $vwap > 0 ? $vwap : (float)($r[4] ?? 0);
                return $vol * $harga;
            },
            fn($r) => (float)($r[4] ?? 0)
        );
    }

    /**
     * Coinbase: [time, low, high, open, close, volBase], terbaru dulu.
     * Volume dalam BTC, dikalikan harga penutupan agar jadi USD.
     */
    protected function fetchCoinbase(string $pair): array
    {
        $data = $this->json("https://api.exchange.coinbase.com/products/{$pair}/candles", [
            'granularity' => 3600,
        ]);

        $rows = $data ?? [];

        return $this->rapikan(
            $rows,
            fn($r) => (float)($r[5] ?? 0) * (float)($r[4] ?? 0),
            fn($r) => (float)($r[4] ?? 0)
        );
    }

    /**
     * Buang candle berjalan, ambil volume dan harga penutupan terakhir.
     *
     * @param array $rows Urut dari yang terbaru.
     */
    protected function rapikan(array $rows, callable $volume, callable $close): array
    {
        if (count($rows) < 2) {
            return ['volumes' => [], 'price' => null];
        }

        // Baris pertama adalah jam berjalan yang belum lengkap
        $lengkap = array_slice($rows, 1, self::WINDOWS['1w']);

        $volumes = [];
        foreach ($lengkap as $r) {
            $v = $volume($r);
            $volumes[] = is_finite($v) && $v > 0 ? $v : 0.0;
        }

        $harga = $close($lengkap[0] ?? []);

        return ['volumes' => $volumes, 'price' => $harga > 0 ? $harga : null];
    }

    protected function json(string $url, array $query = [])
    {
        $response = Http::timeout(12)
            ->withHeaders(['accept' => 'application/json', 'user-agent' => 'pelagic-dashboard/1.0'])
            ->get($url, $query);

        if (!$response->successful()) {
            throw new \RuntimeException('HTTP ' . $response->status());
        }

        return $response->json();
    }

    protected function failure(string $message): array
    {
        return [
            'ok' => false, 'message' => $message, 'exchanges' => [],
            'totals' => array_fill_keys(array_keys(self::WINDOWS), 0.0),
            'price_usd' => null, 'failures' => [], 'fetched_at' => null,
        ];
    }
}
