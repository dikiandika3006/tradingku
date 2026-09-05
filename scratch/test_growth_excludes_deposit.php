<?php
/**
 * Uji: suntikan dana tidak boleh terhitung sebagai pertumbuhan.
 *
 * Pertumbuhan = True Net PNL (kinerja trading murni).
 * Setoran bersih = selisih saldo yang tidak dijelaskan oleh PNL.
 * Rekonsiliasi: Valuasi awal + PNL + Setoran = Valuasi akhir.
 *
 * Semua di dalam transaksi dan di-rollback.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MonthlyPortfolioSnapshot;
use App\Models\Trade;
use App\Services\MonthlyReportService;
use App\Services\TokocryptoService;
use Illuminate\Support\Facades\DB;

class FakeTokocryptoService extends TokocryptoService
{
    public static $price = 100.0;
    public static $amount = 10.0;
    public static $cashUsdt = 0.0;   // saldo USDT (naik saat dana disuntik)

    public function __construct() {}

    public function getAllPrices(): array
    {
        return ['BTCUSDT' => self::$price, 'USDTIDR' => 16000.0];
    }

    public function getPortfolio(): array
    {
        return [
            'usdt_idr_rate' => 16000.0,
            'assets' => [
                ['asset' => 'BTC',  'total' => self::$amount, 'price' => self::$price,
                 'value_usdt' => self::$amount * self::$price],
                ['asset' => 'USDT', 'total' => self::$cashUsdt, 'price' => 1.0,
                 'value_usdt' => self::$cashUsdt],
            ],
        ];
    }
}

app()->instance(TokocryptoService::class, new FakeTokocryptoService());

$mLalu = date('Y-m', strtotime('first day of -1 month'));
$mKini = date('Y-m');

$lulus = true;

function baris(array $reports, string $ym): ?array
{
    foreach ($reports as $r) {
        if ($r['year_month'] === $ym) return $r;
    }
    return null;
}

function periksa(string $label, ?float $dapat, float $harus, bool &$lulus): void
{
    $ok = $dapat !== null && abs($dapat - $harus) < 0.01;
    $lulus = $lulus && $ok;
    printf("  [%s] %-46s %+10.2f (harusnya %+.2f)\n",
        $ok ? 'OK   ' : 'GAGAL', $label, $dapat ?? 0.0, $harus);
}

DB::beginTransaction();

try {
    Trade::query()->delete();
    MonthlyPortfolioSnapshot::query()->delete();

    // Modal: 10 BTC @ $50. Jual-beli impas tiap bulan supaya bulannya terbentuk
    // di laporan tanpa mengubah realized PNL maupun saldo holding.
    Trade::create(['symbol' => 'BTCUSDT', 'type' => 'BUY', 'amount' => 10,
        'price' => 50, 'total' => 500, 'trade_time' => $mLalu . '-01 10:00:00']);

    foreach ([$mLalu, $mKini] as $ym) {
        Trade::create(['symbol' => 'BTCUSDT', 'type' => 'BUY', 'amount' => 1,
            'price' => 50, 'total' => 50, 'trade_time' => $ym . '-05 10:00:00']);
        Trade::create(['symbol' => 'BTCUSDT', 'type' => 'SELL', 'amount' => 1,
            'price' => 50, 'total' => 50, 'trade_time' => $ym . '-06 10:00:00']);
    }

    // Bulan lalu dikunci: harga $60, tanpa kas. Valuasi akhir = 10 x $60 = $600,
    // floating terkunci = 10 x (60 - 50) = +$100.
    MonthlyPortfolioSnapshot::create([
        'year_month' => $mLalu, 'cut_off_date' => $mLalu . '-28 23:55:00',
        'ending_valuation_usdt' => 600.0, 'ending_valuation_idr' => 600.0 * 16000,
        'realized_pnl_usdt' => 0, 'realized_pnl_idr' => 0,
        'floating_pnl_usdt' => 100.0, 'floating_pnl_idr' => 100.0 * 16000,
        'true_net_pnl_usdt' => 100.0, 'true_net_pnl_idr' => 100.0 * 16000,
        'nominal_growth_usdt' => 100.0, 'nominal_growth_idr' => 100.0 * 16000,
        'mom_growth_percent' => 20.0, 'trades_count' => 1, 'win_rate' => 0,
        'health_badge' => 'x', 'top_asset' => 'BTC', 'is_finalized' => true,
    ]);

    $service = app(MonthlyReportService::class);

    // -----------------------------------------------------------------
    // Bulan berjalan: harga naik $60 -> $70 (PNL +$100), DAN dana disuntik $500.
    // Valuasi akhir = 10 x $70 + $500 kas = $1.200
    // Perubahan saldo = 1200 - 600 = +$600  <- angka lama yang menyesatkan
    // Pertumbuhan sebenarnya = floating delta = 200 - 100 = +$100
    // Setoran bersih = 600 - 100 = +$500
    // -----------------------------------------------------------------
    echo "=== KASUS 1: harga naik +\$100 DAN suntik dana \$500 ===\n";
    FakeTokocryptoService::$price = 70.0;
    FakeTokocryptoService::$cashUsdt = 500.0;

    $r = baris($service->build()['reports'], $mKini);

    periksa('valuasi saldo (10x$70 + $500 kas)', $r['portfolio_valuation_usdt'], 1200.0, $lulus);
    periksa('pertumbuhan = PNL saja, bukan +600', $r['nominal_growth_usdt'], 100.0, $lulus);
    periksa('setoran bersih terdeteksi', $r['net_deposit_usdt'], 500.0, $lulus);
    periksa('true net PNL', $r['true_net_pnl_usdt'], 100.0, $lulus);
    periksa('pertumbuhan % (100/600)', $r['mom_growth_percent'], 16.6667, $lulus);

    $rekon = 600.0 + $r['nominal_growth_usdt'] + $r['net_deposit_usdt'];
    periksa('rekonsiliasi: awal + PNL + setoran', $rekon, 1200.0, $lulus);

    // -----------------------------------------------------------------
    echo "\n=== KASUS 2: penarikan dana \$200, harga tetap \$60 ===\n";
    // Valuasi = 10 x $60 - ... kas negatif tidak mungkin, jadi kas = -200 disimulasikan
    // sebagai saldo turun: valuasi akhir = $400 (seolah 200 ditarik keluar).
    // PNL = floating delta = 100 - 100 = 0. Setoran bersih = (400-600) - 0 = -200.
    // -----------------------------------------------------------------
    FakeTokocryptoService::$price = 60.0;
    FakeTokocryptoService::$amount = 10.0;
    FakeTokocryptoService::$cashUsdt = -200.0; // simulasi dana ditarik keluar

    $r = baris($service->build()['reports'], $mKini);

    periksa('valuasi saldo setelah penarikan', $r['portfolio_valuation_usdt'], 400.0, $lulus);
    periksa('pertumbuhan = 0 (harga tak berubah)', $r['nominal_growth_usdt'], 0.0, $lulus);
    periksa('penarikan bersih terdeteksi', $r['net_deposit_usdt'], -200.0, $lulus);

    // -----------------------------------------------------------------
    echo "\n=== KASUS 3: tanpa setoran, murni gerak harga ===\n";
    // Harga $60 -> $80. PNL = 10x(80-50) - 100 = +$200. Valuasi = $800.
    // Setoran bersih harus 0.
    // -----------------------------------------------------------------
    FakeTokocryptoService::$price = 80.0;
    FakeTokocryptoService::$cashUsdt = 0.0;

    $r = baris($service->build()['reports'], $mKini);

    periksa('valuasi saldo (10 x $80)', $r['portfolio_valuation_usdt'], 800.0, $lulus);
    periksa('pertumbuhan (200 - 100)', $r['nominal_growth_usdt'], 200.0, $lulus);
    periksa('setoran bersih nol', $r['net_deposit_usdt'], 0.0, $lulus);

    // -----------------------------------------------------------------
    echo "\n=== Bulan terkunci tidak boleh berubah ===\n";
    // -----------------------------------------------------------------
    $l = baris($service->build()['reports'], $mLalu);
    periksa('valuasi bulan lalu', $l['portfolio_valuation_usdt'], 600.0, $lulus);
    periksa('pertumbuhan bulan lalu', $l['nominal_growth_usdt'], 100.0, $lulus);
    periksa('setoran bulan lalu (bulan pertama)', $l['net_deposit_usdt'], 0.0, $lulus);

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    DB::rollBack();
    echo "\n(Data uji di-rollback.)\n";
}
