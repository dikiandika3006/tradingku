<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$res = \Illuminate\Support\Facades\Http::withHeaders([
    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
])->withoutVerifying()->get("https://fapi.binance.com/fapi/v1/ticker/24hr", ['symbol' => 'STRKUSDT']);

echo "Status: " . $res->status() . "\n";
print_r($res->json());
