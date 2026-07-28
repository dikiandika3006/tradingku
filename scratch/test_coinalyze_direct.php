<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = \App\Models\Setting::where('key', 'coinalyze_api_key')->value('value');
echo "Key: " . substr($key, 0, 8) . "...\n";

// Fetch Coinalyze 24h OHLCV for STRKUSDT_PERP.A
$res = \Illuminate\Support\Facades\Http::get("https://api.coinalyze.net/v1/ohlcv-history", [
    'symbols' => 'STRKUSDT_PERP.A',
    'interval' => '1day',
    'from' => now()->subDays(3)->timestamp,
    'to' => now()->timestamp,
    'api_key' => $key
]);

echo "Coinalyze Status: " . $res->status() . "\n";
print_r($res->json());
