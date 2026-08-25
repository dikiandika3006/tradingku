<?php
/**
 * Uji perhitungan floating PNL bulan berjalan.
 *
 * Floating hanya bisa diukur untuk bulan berjalan (harga historis tidak disimpan),
 * jadi yang diuji adalah: apakah bulan berjalan mengurangi floating yang SUDAH
 * dibukukan di snapshot bulan lalu, sehingga yang dilaporkan hanya pergerakan
 * bulan ini — bukan akumulasi unrealized sepanjang masa.
 *
 * Snapshot bulan lampau dibuat langsung (bukan lewat command), meniru kondisi
 * setelah cut-off 23:55 akhir bulan berjalan sebagaimana di produksi.
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
        return [
            'usdt_idr_rate' => 16000.0,
            'assets' => [[
                'asset' => 'BTC', 'total' => self::$amount,
                'price' => self::$price, 'value_usdt' => self::$amount * self::$price,
            ]],
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
    printf("  [%s] %-44s %+9.2f (harusnya %+.2f)\n",
        $ok ? 'OK   ' : 'GAGAL', $label, $dapat ?? 0.0, $harus);
}

/** Pasang snapshot bulan lalu yang sudah dikunci dengan floating tertentu. */
function kunciBulanLalu(string $ym, float $endingUsdt, float $floating): void
{
    MonthlyPortfolioSnapshot::updateOrCreate(['year_month' => $ym], [
        'cut_off_date'          => $ym . '-28 23:55:00',
        'ending_valuation_usdt' => $endingUsdt,
        'ending_valuation_idr'  => $endingUsdt * 16000,
        'realized_pnl_usdt'     => 0,
        'realized_pnl_idr'      => 0,
        'floating_pnl_usdt'     => $floating,
        'floating_pnl_idr'      => $floating * 16000,
        'true_net_pnl_usdt'     => $floating,
        'true_net_pnl_idr'      => $floating * 16000,
        'nominal_growth_usdt'   => $floating,
        'nominal_growth_idr'    => $floating * 16000,
        'mom_growth_percent'    => 0,
        'trades_count'          => 1,
        'win_rate'              => 0,
        'health_badge'          => 'x',
        'top_asset'             => 'BTC',
        'is_finalized'          => true,
    ]);
}

DB::beginTransaction();

try {
    Trade::query()->delete();
    MonthlyPortfolioSnapshot::query()->delete();

    // Modal: 10 BTC @ $50. Tiap bulan diberi jual-beli impas di harga modal
    // supaya bulannya terbentuk di laporan tanpa mengubah realized PNL,
    // saldo holding, maupun harga rata-rata beli.
    Trade::create(['symbol' => 'BTCUSDT', 'type' => 'BUY', 'amount' => 10,
        'price' => 50, 'total' => 500, 'trade_time' => $mLalu . '-01 10:00:00']);

    foreach ([$mLalu, $mKini] as $ym) {
        Trade::create(['symbol' => 'BTCUSDT', 'type' => 'BUY', 'amount' => 1,
            'price' => 50, 'total' => 50, 'trade_time' => $ym . '-05 10:00:00']);
        Trade::create(['symbol' => 'BTCUSDT', 'type' => 'SELL', 'amount' => 1,
            'price' => 50, 'total' => 50, 'trade_time' => $ym . '-06 10:00:00']);
    }

    $service = app(MonthlyReportService::class);

    // Harga sekarang $70 -> total unrealized = 10 x (70 - 50) = +200
    FakeTokocryptoService::$price = 70.0;

    // -----------------------------------------------------------------
    echo "=== KASUS 1: bulan lalu terkunci dengan floating +\$300 ===\n";
    echo "    (harga sempat \$80, sekarang turun ke \$70)\n";
    // -----------------------------------------------------------------
    kunciBulanLalu($mLalu, 800.0, 300.0);

    $reports = $service->build()['reports'];
    $r = baris($reports, $mKini);

    periksa('floating bulan ini (200 - 300)', $r['floating_pnl_usdt'], -100.0, $lulus);
    periksa('true net PNL', $r['true_net_pnl_usdt'], -100.0, $lulus);
    periksa('valuasi saldo (10 x $70)', $r['portfolio_valuation_usdt'], 700.0, $lulus);
    periksa('pertumbuhan MoM (700 - 800)', $r['nominal_growth_usdt'], -100.0, $lulus);
    periksa('bulan lalu tetap terkunci', baris($reports, $mLalu)['floating_pnl_usdt'], 300.0, $lulus);

    // -----------------------------------------------------------------
    echo "\n=== KASUS 2: bulan lalu terkunci dengan floating \$0 ===\n";
    echo "    (kondisi Juli di produksi - dikunci saat sudah lewat)\n";
    // -----------------------------------------------------------------
    kunciBulanLalu($mLalu, 500.0, 0.0);

    $r = baris($service->build()['reports'], $mKini);

    periksa('floating bulan ini (200 - 0)', $r['floating_pnl_usdt'], 200.0, $lulus);
    periksa('true net PNL', $r['true_net_pnl_usdt'], 200.0, $lulus);
    periksa('pertumbuhan MoM (700 - 500)', $r['nominal_growth_usdt'], 200.0, $lulus);

    // -----------------------------------------------------------------
    echo "\n=== KASUS 3: belum ada snapshot sama sekali ===\n";
    echo "    (tidak ada baseline sah -> floating dilaporkan penuh)\n";
    // -----------------------------------------------------------------
    MonthlyPortfolioSnapshot::query()->delete();

    $r = baris($service->build()['reports'], $mKini);
    periksa('floating bulan ini (penuh)', $r['floating_pnl_usdt'], 200.0, $lulus);

    // -----------------------------------------------------------------
    echo "\n=== Konsistensi: True Net PNL harus = Pertumbuhan Saldo ===\n";
    // -----------------------------------------------------------------
    kunciBulanLalu($mLalu, 800.0, 300.0);
    foreach ($service->build()['reports'] as $r) {
        $ok = abs($r['true_net_pnl_usdt'] - $r['nominal_growth_usdt']) < 0.01;
        $lulus = $lulus && $ok;
        printf("  [%s] %s  true_net=%+8.2f  mom=%+8.2f\n",
            $ok ? 'OK   ' : 'GAGAL', $r['year_month'],
            $r['true_net_pnl_usdt'], $r['nominal_growth_usdt']);
    }

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    DB::rollBack();
    echo "\n(Data uji di-rollback.)\n";
}
