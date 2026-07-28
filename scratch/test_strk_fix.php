<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Test Binance Futures with User-Agent
$binanceRes = \Illuminate\Support\Facades\Http::withHeaders([
    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
])->get("https://fapi.binance.com/fapi/v1/ticker/24hr", ['symbol' => 'STRKUSDT']);

echo "Binance Futures Status: " . $binanceRes->status() . "\n";
if ($binanceRes->successful()) {
    $d = $binanceRes->json();
    echo "Futures Vol 24h: $" . number_format($d['quoteVolume'] / 1000000, 2) . "M (" . round($d['priceChangePercent'], 2) . "%)\n";
}

// Test KuCoin Futures
$kucoinRes = \Illuminate\Support\Facades\Http::get("https://api-futures.kucoin.com/api/v1/contracts/STRKUSDTM");
echo "KuCoin Futures Status: " . $kucoinRes->status() . "\n";
if ($kucoinRes->successful()) {
    $d = $kucoinRes->json()['data'] ?? [];
    $vol = (float)($d['turnoverOf24h'] ?? 0);
    $oi = (float)($d['openInterest'] ?? 0) * (float)($d['markPrice'] ?? 0);
    echo "KuCoin Futures Vol 24h: $" . number_format($vol / 1000000, 2) . "M\n";
    echo "KuCoin Futures OI: $" . number_format($oi / 1000000, 2) . "M\n";
}
