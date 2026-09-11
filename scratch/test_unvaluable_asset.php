<?php
/**
 * Uji aset yang tidak bisa dinilai (fiat tanpa harga pasar, saldo negatif).
 *
 * Saldo IDR bisa minus selama setoran rupiah belum dijurnal masuk. Mengalikan
 * saldo minus itu dengan harga rata-rata menghasilkan modal minus miliaran yang
 * merusak seluruh total di halaman Portofolio. Aset semacam itu harus dilewati:
 * modal nol, PNL nol, tidak ikut total.
 *
 * Semua di dalam transaksi dan di-rollback.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PortfolioController;
use App\Models\Trade;
use App\Services\CostBasisService;
use App\Services\TokocryptoService;
use Illuminate\Support\Facades\DB;

/** Portofolio tiruan menyerupai kondisi nyata: USDT, LUMIA, dan IDR bersaldo minus. */
class FakeTokocryptoService extends TokocryptoService
{
    public function __construct() {}

    public function getAllPrices(): array
    {
        return ['LUMIAUSDT' => 0.0740, 'USDTIDR' => 17615.0];
    }

    public function getPortfolio(): array
    {
        return [
            'usdt_idr_rate' => 17615.0,
            'is_live' => false,
            'assets' => [
                ['asset' => 'USDT',  'free' => 1062.565633, 'locked' => 0.0,
                 'total' => 1062.565633, 'price' => 1.0, 'value_usdt' => 1062.565633,
                 'source' => 'uji'],
                ['asset' => 'LUMIA', 'free' => 2060.97, 'locked' => 0.0,
                 'total' => 2060.97, 'price' => 0.0740, 'value_usdt' => 2060.97 * 0.0740,
                 'source' => 'uji'],
                // Inilah biang kerusakannya: saldo minus, tanpa harga pasar
                ['asset' => 'IDR',   'free' => -2994045.89, 'locked' => 0.0,
                 'total' => -2994045.89, 'price' => 0.0, 'value_usdt' => 0.0,
                 'source' => 'uji'],
            ],
        ];
    }
}

$lulus = true;

function periksa(string $label, ?float $dapat, float $harus, bool &$lulus, int $dp = 2): void
{
    $ok = $dapat !== null && abs($dapat - $harus) < pow(10, -$dp);
    $lulus = $lulus && $ok;
    printf("  [%s] %-42s %18s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label,
        number_format($dapat ?? 0, $dp), number_format($harus, $dp));
}

app()->instance(TokocryptoService::class, new FakeTokocryptoService());

DB::beginTransaction();

try {
    Trade::query()->delete();

    // Riwayat LUMIA asli
    foreach ([
        ['2026-07-16 11:56:00', 'BUY',   387.30, 0.0700, 27.11],
        ['2026-07-17 07:49:00', 'SELL',  387.30, 0.0871, 33.73],
        ['2026-09-04 10:47:00', 'BUY',  1012.19, 0.0820, 83.00],
        ['2026-09-06 21:44:00', 'BUY',  1048.78, 0.0820, 86.00],
    ] as [$w, $t, $a, $p, $tot]) {
        Trade::create(['symbol' => 'LUMIAUSDT', 'type' => $t, 'amount' => $a,
            'price' => $p, 'total' => $tot, 'trade_time' => $w]);
    }

    // Setoran rupiah yang dijurnal dengan simbol "IDR" — inilah yang membuat
    // harga rata-rata IDR menjadi 1.0 dan meledakkan modalnya.
    Trade::create(['symbol' => 'IDR', 'type' => 'DEPOSIT', 'amount' => 1000000,
        'price' => 1.0, 'total' => 1000000, 'trade_time' => '2026-07-16 10:00:00']);

    $controller = app(PortfolioController::class);
    $view = $controller->index(app(CostBasisService::class));
    $data = $view->getData();

    $aset = [];
    foreach ($data['assets'] as $a) $aset[$a['asset']] = $a;

    echo "=== IDR: tidak bisa dinilai, harus netral ===\n";
    periksa('modal IDR', $aset['IDR']['cost_usdt'], 0.0, $lulus);
    periksa('PNL IDR', $aset['IDR']['pnl_usdt'], 0.0, $lulus);
    periksa('PNL % IDR', $aset['IDR']['pnl_percent'], 0.0, $lulus);
    $flagOk = ($aset['IDR']['can_be_valued'] === false);
    $lulus = $lulus && $flagOk;
    printf("  [%s] %-42s %18s (harusnya false)\n", $flagOk ? 'OK   ' : 'GAGAL',
        'ditandai tidak bisa dinilai', $aset['IDR']['can_be_valued'] ? 'true' : 'false');

    echo "\n=== LUMIA: tetap dihitung dengan rata-rata berjalan ===\n";
    periksa('harga rata-rata LUMIA', $aset['LUMIA']['avg_buy_price_usdt'], 0.0820, $lulus, 4);
    periksa('modal LUMIA', $aset['LUMIA']['cost_usdt'], 169.00, $lulus);
    $tglOk = $aset['LUMIA']['first_buy_date'] === '2026-09-04';
    $lulus = $lulus && $tglOk;
    printf("  [%s] %-42s %18s (harusnya 2026-09-04)\n", $tglOk ? 'OK   ' : 'GAGAL',
        'tanggal posisi dibuka', $aset['LUMIA']['first_buy_date'] ?? 'null');

    echo "\n=== Total halaman tidak boleh rusak ===\n";
    periksa('total modal', $data['total_cost_usdt'], 1062.565633 + 169.00, $lulus);
    periksa('total valuasi', $data['total_valuation_usdt'], 1062.565633 + 2060.97 * 0.0740, $lulus);
    periksa('total PNL', $data['total_pnl_usdt'],
        $data['total_valuation_usdt'] - $data['total_cost_usdt'], $lulus);

    $wajar = $data['total_cost_usdt'] > 0 && $data['total_cost_usdt'] < 100000;
    $lulus = $lulus && $wajar;
    echo "  [" . ($wajar ? 'OK   ' : 'GAGAL') . "] total modal berada di rentang wajar "
        . "(sebelum perbaikan: -\$2.992.799)\n";

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    DB::rollBack();
    echo "\n(Data uji di-rollback.)\n";
}
