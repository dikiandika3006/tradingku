<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. Bybit Linear Futures
$bybit = \Illuminate\Support\Facades\Http::get("https://api.bybit.com/v5/market/tickers", [
    'category' => 'linear',
    'symbol' => 'STRKUSDT'
]);
echo "Bybit Status: " . $bybit->status() . "\n";
if ($bybit->successful()) {
    $item = $bybit->json()['result']['list'][0] ?? [];
    echo "Bybit Futures Vol 24h: $" . number_format((float)($item['turnover24h'] ?? 0) / 1000000, 2) . "M\n";
    echo "Bybit Open Interest: $" . number_format(((float)($item['openInterest'] ?? 0) * (float)($item['lastPrice'] ?? 0)) / 1000000, 2) . "M\n";
}

// 2. OKX Futures Swap
$okx = \Illuminate\Support\Facades\Http::get("https://www.okx.com/api/v5/market/ticker", [
    'instId' => 'STRK-USDT-SWAP'
]);
echo "OKX Status: " . $okx->status() . "\n";
if ($okx->successful()) {
    $item = $okx->json()['data'][0] ?? [];
    echo "OKX Futures Vol 24h: $" . number_format((float)($item['volCcy24h'] ?? 0) / 1000000, 2) . "M\n";
}

// 3. Gate.io Futures
$gate = \Illuminate\Support\Facades\Http::get("https://api.gateio.ws/api/v4/futures/usdt/tickers", [
    'contract' => 'STRK_USDT'
]);
echo "Gate Status: " . $gate->status() . "\n";
if ($gate->successful()) {
    $item = $gate->json()[0] ?? [];
    echo "Gate Futures Vol 24h: $" . number_format((float)($item['total_size'] ?? 0) * (float)($item['last'] ?? 0) / 1000000, 2) . "M\n";
}
