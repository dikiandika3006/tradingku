<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$symbol = "STRKUSDT";
$baseCoin = str_replace(['USDT', 'PERP', '-', '_'], '', strtoupper($symbol));

// 1. Search CoinGecko for base coin ID
$searchRes = \Illuminate\Support\Facades\Http::timeout(4)
    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'])
    ->get("https://api.coingecko.com/api/v3/search", ['query' => $baseCoin]);

if ($searchRes->successful()) {
    $coins = $searchRes->json()['coins'] ?? [];
    $coinId = null;
    foreach ($coins as $c) {
        if (strtoupper($c['symbol'] ?? '') === $baseCoin) {
            $coinId = $c['id'];
            break;
        }
    }
    echo "Found CoinGecko ID for $baseCoin: " . ($coinId ?: "NONE") . "\n";

    if ($coinId) {
        $tickersRes = \Illuminate\Support\Facades\Http::timeout(4)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'])
            ->get("https://api.coingecko.com/api/v3/coins/{$coinId}/tickers");

        if ($tickersRes->successful()) {
            $tickers = $tickersRes->json()['tickers'] ?? [];
            $totalVolUsd = 0.0;
            foreach ($tickers as $t) {
                $totalVolUsd += (float)($t['converted_volume']['usd'] ?? 0);
            }
            echo "Global Total Volume (USD): $" . number_format($totalVolUsd / 1000000, 2) . "M\n";
        }
    }
}
