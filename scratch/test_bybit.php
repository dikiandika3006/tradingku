<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$bybit = \Illuminate\Support\Facades\Http::withoutVerifying()->get("https://api.bybit.com/v5/market/tickers", [
    'category' => 'linear',
    'symbol' => 'STRKUSDT'
]);

echo "Bybit Status: " . $bybit->status() . "\n";
if ($bybit->successful()) {
    $item = $bybit->json()['result']['list'][0] ?? [];
    $price = (float)($item['lastPrice'] ?? 0);
    $vol = (float)($item['turnover24h'] ?? 0);
    $oiToken = (float)($item['openInterest'] ?? 0);
    $oiUsd = $oiToken * $price;
    echo "Bybit Price: $" . $price . "\n";
    echo "Bybit Futures Vol 24h: $" . number_format($vol / 1000000, 2) . "M\n";
    echo "Bybit Open Interest USD: $" . number_format($oiUsd / 1000000, 2) . "M\n";
}
