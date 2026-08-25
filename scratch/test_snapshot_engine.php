<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MonthlyPortfolioSnapshot;
use App\Services\MonthlyReportService;

$service = app(MonthlyReportService::class);
$data = $service->build();

echo "Saldo Live Portofolio : $" . number_format($data['live_valuation_usdt'], 2) . "\n";
echo "Floating PNL Total    : $" . number_format($data['total_floating_pnl_usdt'], 2) . "\n";
echo "Kurs USDT/IDR         : " . number_format($data['usdt_idr_rate'], 0, ',', '.') . "\n";
echo "Snapshot terkunci     : " . MonthlyPortfolioSnapshot::where('is_finalized', true)->count() . " bulan\n";
echo str_repeat('=', 100) . "\n";

printf("%-10s %-8s %12s %14s %14s %14s %10s\n",
    'Periode', 'Status', 'Trades', 'Realized', 'Floating', 'Saldo Akhir', 'MoM %');
echo str_repeat('-', 100) . "\n";

foreach ($data['reports'] as $r) {
    printf("%-10s %-8s %12d %14s %14s %14s %9s%%\n",
        $r['year_month'],
        $r['is_finalized'] ? 'LOCKED' : 'LIVE',
        $r['trades_count'],
        '$' . number_format($r['realized_pnl_usdt'], 2),
        '$' . number_format($r['floating_pnl_usdt'], 2),
        '$' . number_format($r['portfolio_valuation_usdt'], 2),
        number_format($r['mom_growth_percent'], 2)
    );
}
