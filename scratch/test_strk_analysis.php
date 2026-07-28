<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = app(\App\Services\TechnicalAnalysisService::class);
$res = $service->calculateSwingSetup('STRKUSDT');

echo "Symbol: " . $res['symbol'] . "\n";
echo "Volume Futures (24h): " . $res['volume_24h_formatted'] . " (" . round($res['volume_change_pct'], 2) . "%)\n";
echo "Open Interest (OI): " . $res['oi_value_formatted'] . " (" . round($res['oi_change_pct'], 2) . "%)\n";
echo "Reversal Ratio: " . $res['reversal_ratio'] . "x\n";
echo "Reversal Class: " . $res['reversal_class'] . "\n";
echo "Reversal Note: " . $res['reversal_note'] . "\n";
