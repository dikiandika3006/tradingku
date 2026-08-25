<?php
/**
 * Uji Monthly Portfolio Snapshot Engine.
 *
 * Skenario: buat trade dummy 3 bulan, kunci 2 bulan lampau, lalu naikkan harga
 * koin drastis. Bulan yang sudah dikunci harus TIDAK berubah sama sekali.
 * Semua perubahan dibatalkan (rollback) di akhir.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MonthlyPortfolioSnapshot;
use App\Models\Trade;
use App\Services\MonthlyReportService;
use App\Services\TokocryptoService;
use Illuminate\Support\Facades\DB;

/** Fake service supaya harga & saldo bisa dikendalikan. */
class FakeTokocryptoService extends TokocryptoService
{
    public static $btcPrice = 100.0;
    public static $btcAmount = 10.0;

    public function __construct() {}

    public function getAllPrices(): array
    {
        return ['BTCUSDT' => self::$btcPrice, 'USDTIDR' => 16000.0];
    }

    public function getPortfolio(): array
    {
        $value = self::$btcAmount * self::$btcPrice;

        return [
            'usdt_idr_rate' => 16000.0,
            'assets' => [
                ['asset' => 'BTC', 'total' => self::$btcAmount, 'price' => self::$btcPrice, 'value_usdt' => $value],
            ],
        ];
    }
}

app()->instance(TokocryptoService::class, new FakeTokocryptoService());

$currentYm = date('Y-m');
$m1 = date('Y-m', strtotime('first day of -2 month'));
$m2 = date('Y-m', strtotime('first day of -1 month'));

DB::beginTransaction();

try {
    // Bersihkan agar isolasi bersih (di dalam transaksi, akan di-rollback)
    Trade::query()->delete();
    MonthlyPortfolioSnapshot::query()->delete();

    // Beli 20 BTC @ $50, jual 10 BTC @ $80 di bulan m1 -> realized +$300
    Trade::create(['symbol' => 'BTCUSDT', 'type' => 'BUY',  'amount' => 20, 'price' => 50, 'total' => 1000,  'trade_time' => $m1 . '-05 10:00:00']);
    Trade::create(['symbol' => 'BTCUSDT', 'type' => 'SELL', 'amount' => 10, 'price' => 80, 'total' => 800,  'trade_time' => $m1 . '-20 10:00:00']);
    // Jual 5 BTC @ $90 di bulan m2 -> realized +$200
    Trade::create(['symbol' => 'BTCUSDT', 'type' => 'SELL', 'amount' => 5,  'price' => 90, 'total' => 450,  'trade_time' => $m2 . '-15 10:00:00']);

    FakeTokocryptoService::$btcAmount = 5.0;
    FakeTokocryptoService::$btcPrice  = 100.0;

    $service = app(MonthlyReportService::class);

    echo "=== TAHAP 1: Sebelum cut-off (harga BTC \$100, semua LIVE) ===\n";
    $before = [];
    foreach ($service->build()['reports'] as $r) {
        $before[$r['year_month']] = $r;
        printf("  %s [%s] realized=%+8.2f floating=%+8.2f saldo_akhir=%9.2f mom=%+6.2f%%\n",
            $r['year_month'], $r['is_finalized'] ? 'LOCKED' : 'LIVE',
            $r['realized_pnl_usdt'], $r['floating_pnl_usdt'],
            $r['portfolio_valuation_usdt'], $r['mom_growth_percent']);
    }

    echo "\n=== TAHAP 2: Jalankan cut-off (backfill) ===\n";
    Artisan::call('portfolio:snapshot', ['--backfill' => true]);
    echo Artisan::output();

    echo "=== TAHAP 3: Harga BTC MELEDAK \$100 -> \$1000 ===\n";
    FakeTokocryptoService::$btcPrice = 1000.0;

    $after = [];
    foreach ($service->build()['reports'] as $r) {
        $after[$r['year_month']] = $r;
        printf("  %s [%s] realized=%+8.2f floating=%+8.2f saldo_akhir=%9.2f mom=%+6.2f%%\n",
            $r['year_month'], $r['is_finalized'] ? 'LOCKED' : 'LIVE',
            $r['realized_pnl_usdt'], $r['floating_pnl_usdt'],
            $r['portfolio_valuation_usdt'], $r['mom_growth_percent']);
    }

    echo "\n=== HASIL VERIFIKASI ===\n";
    $pass = true;

    foreach ([$m1, $m2] as $ym) {
        $b = $before[$ym] ?? null;
        $a = $after[$ym] ?? null;

        if (!$a || !$a['is_finalized']) {
            echo "  [GAGAL] {$ym} tidak terkunci setelah cut-off\n";
            $pass = false;
            continue;
        }

        $drift = abs($a['portfolio_valuation_usdt'] - $b['portfolio_valuation_usdt']);
        if ($drift < 0.01) {
            printf("  [OK]    %s saldo akhir TETAP \$%.2f meski harga naik 10x\n", $ym, $a['portfolio_valuation_usdt']);
        } else {
            printf("  [GAGAL] %s saldo akhir bergeser \$%.2f (dari %.2f ke %.2f)\n",
                $ym, $drift, $b['portfolio_valuation_usdt'], $a['portfolio_valuation_usdt']);
            $pass = false;
        }
    }

    $liveNow = $after[$currentYm];
    if ($liveNow['is_finalized']) {
        echo "  [GAGAL] Bulan berjalan {$currentYm} ikut terkunci, seharusnya LIVE\n";
        $pass = false;
    } elseif (abs($liveNow['portfolio_valuation_usdt'] - 5000.0) < 0.01) {
        printf("  [OK]    Bulan berjalan %s bergerak LIVE mengikuti harga: \$%.2f (5 BTC x \$1000)\n",
            $currentYm, $liveNow['portfolio_valuation_usdt']);
    } else {
        printf("  [GAGAL] Bulan berjalan %s = \$%.2f, harusnya \$5000.00\n",
            $currentYm, $liveNow['portfolio_valuation_usdt']);
        $pass = false;
    }

    // Cut-off ulang tanpa --force harus ditolak
    echo "\n=== TAHAP 4: Coba timpa bulan terkunci tanpa --force ===\n";
    Artisan::call('portfolio:snapshot', ['--month' => $m1]);
    $out = Artisan::output();
    echo $out;
    if (str_contains($out, 'sudah dikunci')) {
        echo "  [OK]    Snapshot terkunci menolak ditimpa\n";
    } else {
        echo "  [GAGAL] Snapshot terkunci bisa ditimpa tanpa --force\n";
        $pass = false;
    }

    echo "\n" . ($pass ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    DB::rollBack();
    echo "\n(Semua data uji sudah di-rollback, database kembali seperti semula.)\n";
}
