<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Trade;
use App\Services\TokocryptoService;

$service = app(TokocryptoService::class);
$portfolio = $service->getPortfolio();
$usdtIdr = $portfolio['usdt_idr_rate'] ?? 16000.0;

// 1. Calculate Actual Live Total Portfolio Valuation (USDT)
$livePortfolioValuationUsdt = 0.0;
foreach ($portfolio['assets'] ?? [] as $assetData) {
    $livePortfolioValuationUsdt += (float)($assetData['value_usdt'] ?? 0);
}

echo "Actual Live Portfolio Balance: $" . number_format($livePortfolioValuationUsdt, 2) . " (Rp " . number_format($livePortfolioValuationUsdt * $usdtIdr, 0, ',', '.') . ")\n";
echo "=========================================================\n";

// Simulated monthly net PNLs
$monthlyPnls = [
    '2026-06' => 100.0,
    '2026-07' => 150.0,
    '2026-08' => 50.0,  // Current month
];

// Reconstruct historical monthly ending balances backwards from current live balance
$reversedYms = array_reverse(array_keys($monthlyPnls));
$historicalValuations = [];
$currentRunningBalance = $livePortfolioValuationUsdt;

foreach ($reversedYms as $ym) {
    $pnl = $monthlyPnls[$ym];
    $historicalValuations[$ym] = [
        'ending_balance' => $currentRunningBalance,
        'pnl' => $pnl,
        'starting_balance' => $currentRunningBalance - $pnl
    ];
    $currentRunningBalance -= $pnl;
}

$monthlyReports = [];
$prevEndingBalance = null;

// Re-sort chronologically for report display
ksort($historicalValuations);

foreach ($historicalValuations as $ym => $valData) {
    $endingBal = $valData['ending_balance'];
    $startingBal = $valData['starting_balance'];
    $nominalGrowth = $prevEndingBalance !== null ? ($endingBal - $prevEndingBalance) : $valData['pnl'];
    $growthPercent = $prevEndingBalance !== null && $prevEndingBalance > 0 ? ($nominalGrowth / $prevEndingBalance) * 100.0 : (($startingBal > 0) ? ($nominalGrowth / $startingBal) * 100.0 : 0.0);

    echo "Periode: $ym\n";
    echo "  Valuasi Akhir Bulan: $" . number_format($endingBal, 2) . " (Rp " . number_format($endingBal * $usdtIdr, 0, ',', '.') . ")\n";
    echo "  Pertumbuhan Nominal: " . ($nominalGrowth >= 0 ? '+' : '') . "$" . number_format($nominalGrowth, 2) . "\n";
    echo "  Pertumbuhan MoM (%): " . ($growthPercent >= 0 ? '+' : '') . number_format($growthPercent, 2) . "%\n";
    echo "---------------------------------------------------------\n";

    $prevEndingBalance = $endingBal;
}
