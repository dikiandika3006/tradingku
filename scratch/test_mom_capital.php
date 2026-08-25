<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Trade;
use App\Services\TokocryptoService;

$service = app(TokocryptoService::class);
$portfolio = $service->getPortfolio();

// Initial portfolio capital baseline (Buy Cost)
$initialCapitalUsdt = 0.0;
foreach ($portfolio['assets'] ?? [] as $assetData) {
    $totalAmount = (float)$assetData['total'];
    $price = (float)$assetData['price'];
    $valueUsdt = (float)$assetData['value_usdt'];
    $initialCapitalUsdt += $valueUsdt;
}

if ($initialCapitalUsdt <= 0) {
    $initialCapitalUsdt = 1000.0; // Fallback baseline if empty
}

// Simulated monthly cumulative true net PNLs
$simulatedMonths = [
    ['month' => 'Januari 2026', 'true_cumulative_pnl' => 50.0],
    ['month' => 'Februari 2026', 'true_cumulative_pnl' => 155.0],
    ['month' => 'Maret 2026', 'true_cumulative_pnl' => 110.0],
];

$prevEquity = $initialCapitalUsdt;
echo "Initial Starting Capital Baseline: $" . number_format($initialCapitalUsdt, 2) . "\n";
echo "=============================================\n";

foreach ($simulatedMonths as $m) {
    $currentEquity = $initialCapitalUsdt + $m['true_cumulative_pnl'];
    $momGrowth = (($currentEquity - $prevEquity) / $prevEquity) * 100.0;

    echo "Bulan: " . $m['month'] . "\n";
    echo "  Ending Equity: $" . number_format($currentEquity, 2) . "\n";
    echo "  MoM Capital Growth: " . ($momGrowth >= 0 ? '+' : '') . number_format($momGrowth, 2) . "%\n";
    echo "---------------------------------------------\n";

    $prevEquity = $currentEquity;
}
