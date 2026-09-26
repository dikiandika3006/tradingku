<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Klien API resmi CoinGlass (open-api v4).
 *
 * Dipakai untuk mengambil heatmap volume spot: volume per bursa pada beberapa
 * rentang waktu sekaligus (1 jam, 4 jam, 12 jam, 24 jam, 7 hari).
 *
 * Sengaja memakai API resmi, bukan mengurai halaman web. Situsnya memuat heatmap
 * lewat endpoint internal yang butuh header tersembunyi dan bisa berubah kapan
 * saja; API resmi memberi data yang sama dengan kontrak yang stabil.
 */
class CoinglassService
{
    protected const BASE_URL = 'https://open-api-v4.coinglass.com';

    /** Pemetaan akhiran field API ke kunci timeframe internal. */
    protected const TIMEFRAMES = ['1h', '4h', '12h', '24h', '1w'];

    /** Hasil ditahan sebentar supaya membuka halaman berkali-kali tidak menguras kuota. */
    protected const CACHE_SECONDS = 60;

    /**
     * Apakah API key sudah tersedia.
     */
    public function hasApiKey(): bool
    {
        return $this->apiKey() !== '';
    }

    /**
     * Ambil heatmap volume spot untuk sebuah aset.
     *
     * @return array{ok: bool, message: string|null, rows: array<int, array>, fetched_at: \Carbon\Carbon|null}
     */
    public function spotVolumeHeatmap(string $symbol = 'BTC', bool $useCache = true): array
    {
        $symbol = strtoupper(trim($symbol));
        $cacheKey = "coinglass_spot_heatmap_{$symbol}";

        if ($useCache && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $result = $this->fetchHeatmap($symbol);

        // Hanya hasil yang berhasil yang layak di-cache
        if ($result['ok']) {
            Cache::put($cacheKey, $result, self::CACHE_SECONDS);
        }

        return $result;
    }

    protected function fetchHeatmap(string $symbol): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === '') {
            return $this->failure('CoinGlass API key belum diisi. Isi di halaman API Settings.');
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'CG-API-KEY' => $apiKey,
                    'accept'     => 'application/json',
                ])
                ->get(self::BASE_URL . '/api/spot/pairs-markets', ['symbol' => $symbol]);
        } catch (\Throwable $e) {
            Log::warning('CoinGlass request gagal: ' . $e->getMessage());
            return $this->failure('Gagal menghubungi CoinGlass: ' . $e->getMessage());
        }

        if (!$response->successful()) {
            return $this->failure(sprintf(
                'CoinGlass membalas HTTP %d. %s',
                $response->status(),
                $this->extractMessage($response->json())
            ));
        }

        $body = $response->json();

        // Envelope CoinGlass: {"code":"0","msg":"success","data":[...]}
        if (isset($body['code']) && (string)$body['code'] !== '0') {
            return $this->failure('CoinGlass menolak permintaan: ' . $this->extractMessage($body));
        }

        $data = $body['data'] ?? null;

        if (!is_array($data)) {
            return $this->failure('Respons CoinGlass tidak berisi data yang bisa dibaca.');
        }

        $rows = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = $this->normalise($item, $symbol);

            // Baris tanpa satu pun angka volume tidak berguna untuk heatmap
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if (empty($rows)) {
            return $this->failure('CoinGlass tidak mengembalikan baris volume untuk ' . $symbol . '.');
        }

        // Bursa dengan volume 24 jam terbesar di atas
        usort($rows, fn($a, $b) => ($b['volume_usd_24h'] ?? 0) <=> ($a['volume_usd_24h'] ?? 0));

        return [
            'ok'         => true,
            'message'    => null,
            'rows'       => $rows,
            'fetched_at' => now(),
        ];
    }

    /**
     * Ubah satu baris API menjadi bentuk yang dipakai aplikasi.
     *
     * Toleran terhadap perbedaan penamaan: CoinGlass pernah memakai camelCase
     * dan snake_case di versi berbeda.
     */
    protected function normalise(array $item, string $symbol): ?array
    {
        $row = [
            'symbol'        => $symbol,
            'exchange_name' => (string)($this->pick($item, ['exchange_name', 'exchangeName', 'exchange']) ?? ''),
            'pair'          => $this->pick($item, ['symbol', 'instrument_id', 'pair']),
            'price_usd'     => $this->num($this->pick($item, ['price_usd', 'price', 'priceUsd'])),
            'volume_change_percent_24h' => $this->num(
                $this->pick($item, ['volume_usd_change_percent_24h', 'volumeUsdChangePercent24h'])
            ),
        ];

        if ($row['exchange_name'] === '') {
            return null;
        }

        // "pair" bisa saja sama dengan nama aset; simpan apa adanya
        $row['pair'] = $row['pair'] !== null ? (string)$row['pair'] : null;

        $adaVolume = false;

        foreach (self::TIMEFRAMES as $tf) {
            $volume = $this->num($this->pick($item, [
                "volume_usd_{$tf}",
                'volumeUsd' . ucfirst($tf),
            ]));

            $change = $this->num($this->pick($item, [
                "price_change_percent_{$tf}",
                'priceChangePercent' . ucfirst($tf),
            ]));

            $row["volume_usd_{$tf}"] = $volume;
            $row["price_change_percent_{$tf}"] = $change;

            if ($volume !== null) {
                $adaVolume = true;
            }
        }

        return $adaVolume ? $row : null;
    }

    /** Ambil nilai pertama yang ada dari beberapa kemungkinan nama field. */
    protected function pick(array $item, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== null && $item[$key] !== '') {
                return $item[$key];
            }
        }

        return null;
    }

    protected function num($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    protected function extractMessage($body): string
    {
        if (is_array($body)) {
            return (string)($body['msg'] ?? $body['message'] ?? 'Tidak ada keterangan.');
        }

        return 'Tidak ada keterangan.';
    }

    protected function failure(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'rows' => [], 'fetched_at' => null];
    }

    /** Key dibaca dari settings dulu, baru jatuh ke .env. */
    protected function apiKey(): string
    {
        $fromSettings = Setting::where('key', 'coinglass_api_key')->value('value');

        if (is_string($fromSettings) && trim($fromSettings) !== '') {
            return trim($fromSettings);
        }

        return trim((string)env('COINGLASS_API_KEY', ''));
    }
}
