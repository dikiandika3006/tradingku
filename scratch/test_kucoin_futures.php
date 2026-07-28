<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// 1. Trade Statistics (24h volume)
$stats = \Illuminate\Support\Facades\Http::get("https://api-futures.kucoin.com/api/v1/trade/statistics", ['symbol' => 'STRKUSDTM']);
echo "Trade Stats (24h Volume):\n";
print_r($stats->json());

// 2. Open Interest
$contract = \Illuminate\Support\Facades\Http::get("https://api-futures.kucoin.com/api/v1/contracts/STRKUSDTM");
echo "Contract Info (OI):\n";
print_r($contract->json());
