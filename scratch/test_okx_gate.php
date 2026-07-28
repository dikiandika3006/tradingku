<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. OKX Swap Ticker
$okx = \Illuminate\Support\Facades\Http::withoutVerifying()->get("https://www.okx.com/api/v5/market/ticker", ['instId' => 'STRK-USDT-SWAP']);
echo "OKX Swap Status: " . $okx->status() . "\n";
print_r($okx->json());

// 2. Gate.io Futures Ticker
$gate = \Illuminate\Support\Facades\Http::withoutVerifying()->get("https://api.gateio.ws/api/v4/futures/usdt/tickers", ['contract' => 'STRK_USDT']);
echo "Gate Futures Status: " . $gate->status() . "\n";
print_r($gate->json());
