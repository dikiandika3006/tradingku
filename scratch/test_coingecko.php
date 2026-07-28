<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. CoinGecko Starknet Tickers
try {
    $res = \Illuminate\Support\Facades\Http::timeout(5)
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'])
        ->get("https://api.coingecko.com/api/v3/coins/starknet/tickers");

    echo "CoinGecko Status: " . $res->status() . "\n";
    if ($res->successful()) {
        $tickers = $res->json()['tickers'] ?? [];
        $totalVol = 0.0;
        foreach ($tickers as $t) {
            $totalVol += (float)($t['converted_volume']['usd'] ?? 0);
        }
        echo "CoinGecko Total Volume across all exchanges: $" . number_format($totalVol / 1000000, 2) . "M\n";
    }
} catch (\Exception $e) {
    echo "CoinGecko Error: " . $e->getMessage() . "\n";
}

// 2. CryptoCompare 24h Volume
try {
    $res = \Illuminate\Support\Facades\Http::timeout(5)
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'])
        ->get("https://min-api.cryptocompare.com/data/v2/histohour", [
            'fsym' => 'STRK',
            'tsym' => 'USD',
            'limit' => 24
        ]);

    echo "CryptoCompare Status: " . $res->status() . "\n";
    if ($res->successful()) {
        $data = $res->json()['Data']['Data'] ?? [];
        $vol24 = 0.0;
        foreach ($data as $d) {
            $vol24 += (float)($d['volumeto'] ?? 0);
        }
        echo "CryptoCompare 24h Volume: $" . number_format($vol24 / 1000000, 2) . "M\n";
    }
} catch (\Exception $e) {
    echo "CryptoCompare Error: " . $e->getMessage() . "\n";
}
