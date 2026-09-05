<?php
/**
 * Uji kolom "Floating Berjalan" dan baseline floating multi-bulan.
 *
 * Yang tersimpan di snapshot adalah PERUBAHAN floating per bulan. Baseline untuk
 * bulan berjalan karenanya harus AKUMULASI seluruh bulan sebelumnya, bukan hanya
 * bulan terakhir. Membaca satu bulan terakhir saja kebetulan benar selama
 * bulan-bulan awal masih nol, lalu meleset begitu ada dua bulan berisi.
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

    public function __construct() {}

    public function getAllPrices(): array
    {
        return ['BTCUSDT' => self::$price, 'USDTIDR' => 16000.0];
    }

    public function getPortfolio(): array
    {
        return ['usdt_idr_rate' => 16000.0, 'assets' => [[
            'asset' => 'BTC', 'total' => self::$amount, 'price' => self::$price,
            'value_usdt' => self::$amount * self::$price,
        ]]];
    }
}

app()->instance(TokocryptoService::class, new FakeTokocryptoService());

$m1 = date('Y-m', strtotime('first day of -2 month'));
$m2 = date('Y-m', strtotime('first day of -1 month'));
$m3 = date('Y-m');

$lulus = true;

function baris(array $reports, string $ym): ?array
{
    foreach ($reports as $r) if ($r['year_month'] === $ym) return $r;
    return null;
}

function periksa(string $label, ?float $dapat, float $harus, bool &$lulus): void
{
    $ok = $dapat !== null && abs($dapat - $harus) < 0.01;
    $lulus = $lulus && $ok;
    printf("  [%s] %-44s %+9.2f (harusnya %+.2f)\n", $ok ? 'OK   ' : 'GAGAL', $label, $dapat ?? 0.0, $harus);
}

function kunci(string $ym, float $ending, float $floatingDelta, float $realized = 0.0): void
{
    $trueNet = $realized + $floatingDelta;
    MonthlyPortfolioSnapshot::updateOrCreate(['year_month' => $ym], [
        'cut_off_date' => $ym . '-28 23:55:00',
        'ending_valuation_usdt' => $ending, 'ending_valuation_idr' => $ending * 16000,
        'realized_pnl_usdt' => $realized, 'realized_pnl_idr' => $realized * 16000,
        'floating_pnl_usdt' => $floatingDelta, 'floating_pnl_idr' => $floatingDelta * 16000,
        'true_net_pnl_usdt' => $trueNet, 'true_net_pnl_idr' => $trueNet * 16000,
        'nominal_growth_usdt' => $trueNet, 'nominal_growth_idr' => $trueNet * 16000,
        'mom_growth_percent' => 0, 'trades_count' => 1, 'win_rate' => 0,
        'health_badge' => 'x', 'top_asset' => 'BTC', 'is_finalized' => true,
    ]);
}

DB::beginTransaction();

try {
    Trade::query()->delete();
    MonthlyPortfolioSnapshot::query()->delete();

    // Modal 10 BTC @ $50, plus jual-beli impas tiap bulan agar bulannya terbentuk
    Trade::create(['symbol' => 'BTCUSDT', 'type' => 'BUY', 'amount' => 10,
        'price' => 50, 'total' => 500, 'trade_time' => $m1 . '-01 10:00:00']);
    foreach ([$m1, $m2, $m3] as $ym) {
        Trade::create(['symbol' => 'BTCUSDT', 'type' => 'BUY', 'amount' => 1,
            'price' => 50, 'total' => 50, 'trade_time' => $ym . '-05 10:00:00']);
        Trade::create(['symbol' => 'BTCUSDT', 'type' => 'SELL', 'amount' => 1,
            'price' => 50, 'total' => 50, 'trade_time' => $ym . '-06 10:00:00']);
    }

    $service = app(MonthlyReportService::class);

    // Dua bulan lampau terkunci, MASING-MASING dengan perubahan floating tak nol.
    // Inilah kondisi yang membongkar bug baseline: akumulasi = +100 + 50 = +150.
    kunci($m1, 600.0, 100.0);   // unrealized bergerak 0   -> +100
    kunci($m2, 650.0,  50.0);   // unrealized bergerak 100 -> +150

    // Harga sekarang $70 -> total unrealized = 10 x (70 - 50) = +200
    FakeTokocryptoService::$price = 70.0;

    echo "=== Baseline harus akumulasi (+100 dan +50), bukan hanya +50 ===\n";
    $reports = $service->build()['reports'];
    $r3 = baris($reports, $m3);

    periksa('perubahan floating bulan ini (200-150)', $r3['floating_pnl_usdt'], 50.0, $lulus);
    periksa('floating berjalan = total unrealized', $r3['floating_running_usdt'], 200.0, $lulus);
    periksa('kinerja bulan ini', $r3['true_net_pnl_usdt'], 50.0, $lulus);

    echo "\n=== Floating berjalan tiap bulan = akumulasi perubahan ===\n";
    $akumulasi = 0.0;
    foreach ($reports as $r) {
        $akumulasi += $r['floating_pnl_usdt'];
        $ok = abs($r['floating_running_usdt'] - $akumulasi) < 0.01;
        $lulus = $lulus && $ok;
        printf("  [%s] %s  perubahan=%+8.2f  berjalan=%+8.2f\n",
            $ok ? 'OK   ' : 'GAGAL', $r['year_month'],
            $r['floating_pnl_usdt'], $r['floating_running_usdt']);
    }

    periksa('berjalan bulan terakhir = unrealized nyata', $akumulasi, 200.0, $lulus);

    echo "\n=== Bulan terkunci tidak berubah ===\n";
    periksa($m1 . ' perubahan floating', baris($reports, $m1)['floating_pnl_usdt'], 100.0, $lulus);
    periksa($m2 . ' perubahan floating', baris($reports, $m2)['floating_pnl_usdt'], 50.0, $lulus);
    periksa($m2 . ' floating berjalan', baris($reports, $m2)['floating_running_usdt'], 150.0, $lulus);

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    DB::rollBack();
    echo "\n(Data uji di-rollback.)\n";
}
