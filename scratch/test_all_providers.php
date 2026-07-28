<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. Test Binance api1 / api2 / api3
$endpoints = [
    "https://api1.binance.com/api/v3/ticker/24hr?symbol=STRKUSDT",
    "https://api2.binance.com/api/v3/ticker/24hr?symbol=STRKUSDT",
    "https://api3.binance.com/api/v3/ticker/24hr?symbol=STRKUSDT",
    "https://data-api.binance.vision/api/v3/ticker/24hr?symbol=STRKUSDT",
    "https://open-api-v3.coinglass.com/api/futures/openInterest/chart?symbol=STRK&interval=0",
    "https://api.coingecko.com/api/v3/coins/starknet/tickers"
];

foreach ($endpoints as $url) {
    try {
        $res = \Illuminate\Support\Facades\Http::timeout(4)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'])
            ->get($url);
        echo "URL: $url\n";
        echo "Status: " . $res->status() . "\n";
        if ($res->successful()) {
            $data = $res->json();
            echo "Sample: " . substr(json_encode($data), 0, 150) . "...\n";
        }
        echo "----------------------------------------\n";
    } catch (\Exception $e) {
        echo "URL: $url => ERROR: " . $e->getMessage() . "\n";
    }
}
