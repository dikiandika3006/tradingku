<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. KuCoin Futures Contract Ticker
$kucoinFut = \Illuminate\Support\Facades\Http::get("https://api-futures.kucoin.com/api/v1/ticker", ['symbol' => 'STRKUSDTM']);
echo "KuCoin Futures Status: " . $kucoinFut->status() . "\n";
print_r($kucoinFut->json());

// 2. Binance Spot Ticker 24hr
$binanceSpot = \Illuminate\Support\Facades\Http::get("https://api.binance.com/api/v3/ticker/24hr", ['symbol' => 'STRKUSDT']);
echo "Binance Spot Status: " . $binanceSpot->status() . "\n";
print_r($binanceSpot->json());
