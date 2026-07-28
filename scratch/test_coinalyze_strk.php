<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$key = \App\Models\Setting::where('key', 'coinalyze_api_key')->value('value');
echo "API Key: " . ($key ? "Found (" . strlen($key) . " chars)" : "NOT FOUND") . "\n";

if (!$key) exit;

$symbol = "STRKUSDT_PERP.A";

// 1. Fetch OHLCV
$ohlcv = \Illuminate\Support\Facades\Http::get("https://api.coinalyze.net/v1/ohlcv-history", [
    'symbols' => $symbol,
    'interval' => '4hour',
    'from' => now()->subDays(2)->timestamp,
    'to' => now()->timestamp,
    'api_key' => $key
])->json();

echo "--- OHLCV --- \n";
print_r(array_slice($ohlcv[0]['history'] ?? [], -3));

// 2. Fetch Open Interest
$oi = \Illuminate\Support\Facades\Http::get("https://api.coinalyze.net/v1/open-interest-history", [
    'symbols' => $symbol,
    'interval' => '4hour',
    'from' => now()->subDays(2)->timestamp,
    'to' => now()->timestamp,
    'api_key' => $key
])->json();

echo "--- Open Interest --- \n";
print_r(array_slice($oi[0]['history'] ?? [], -3));
