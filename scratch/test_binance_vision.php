<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$res = \Illuminate\Support\Facades\Http::get("https://data-api.binance.vision/api/v3/ticker/24hr", ['symbol' => 'STRKUSDT']);
echo "Status: " . $res->status() . "\n";
print_r($res->json());
