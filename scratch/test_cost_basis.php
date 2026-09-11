<?php
/**
 * Uji perhitungan modal (cost basis) rata-rata berjalan.
 *
 * Memakai riwayat LUMIA yang sebenarnya: posisi Juli dibeli lalu dijual habis,
 * posisi baru dibangun di September. Harga beli Juli TIDAK boleh lagi ikut
 * menentukan rata-rata posisi September.
 *
 * Semua di dalam transaksi dan di-rollback.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Trade;
use App\Services\CostBasisService;
use Illuminate\Support\Facades\DB;

$lulus = true;

function periksa(string $label, ?float $dapat, float $harus, bool &$lulus, int $dp = 4): void
{
    $ok = $dapat !== null && abs($dapat - $harus) < pow(10, -$dp);
    $lulus = $lulus && $ok;
    printf("  [%s] %-44s %12s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label,
        number_format($dapat ?? 0, $dp), number_format($harus, $dp));
}

DB::beginTransaction();

try {
    Trade::query()->delete();

    $prices = ['LUMIAUSDT' => 0.0848, 'USDTIDR' => 17607.0];

    // Riwayat LUMIA sebenarnya
    $jurnal = [
        ['2026-07-16 11:56:00', 'BUY',   387.30,   0.0700,  27.11],
        ['2026-07-17 07:49:00', 'SELL',  387.30,   0.0871,  33.73],
        ['2026-09-04 10:47:00', 'BUY',  1012.19,   0.0820,  83.00],
        ['2026-09-06 21:44:00', 'BUY',  1048.78,   0.0820,  86.00],
    ];

    foreach ($jurnal as [$w, $t, $a, $p, $tot]) {
        Trade::create(['symbol' => 'LUMIAUSDT', 'type' => $t, 'amount' => $a,
            'price' => $p, 'total' => $tot, 'trade_time' => $w]);
    }

    $svc = app(CostBasisService::class);

    echo "=== UJI 1: posisi yang sempat ditutup tidak menyeret rata-rata lama ===\n";
    $basis = $svc->forAsset('LUMIA', $prices);

    periksa('sisa posisi', $basis['amount'], 2060.97, $lulus, 2);
    periksa('harga rata-rata beli', $basis['avg_price_usdt'], 0.0820, $lulus);
    periksa('modal', $basis['cost_usdt'], 169.00, $lulus, 2);
    $bukaOk = $basis['opened_at'] && $basis['opened_at']->format('Y-m-d') === '2026-09-04';
    $lulus = $lulus && $bukaOk;
    printf("  [%s] %-44s %12s (harusnya 2026-09-04)\n", $bukaOk ? 'OK   ' : 'GAGAL',
        'tanggal posisi dibuka', $basis['opened_at'] ? $basis['opened_at']->format('Y-m-d') : 'null');

    echo "\n=== UJI 2: metode lama memang menghasilkan angka berbeda ===\n";
    $amt = 0; $cost = 0;
    foreach ($jurnal as [$w, $t, $a, $p]) if ($t === 'BUY') { $amt += $a; $cost += $a * $p; }
    $avgLama = $cost / $amt;
    printf("  rata-rata semua BUY (lama)  : \$%.4f  modal \$%.2f\n", $avgLama, 2060.97 * $avgLama);
    printf("  rata-rata berjalan (baru)   : \$%.4f  modal \$%.2f\n",
        $basis['avg_price_usdt'], $basis['cost_usdt']);
    $selisih = $basis['cost_usdt'] - (2060.97 * $avgLama);
    printf("  selisih modal               : \$%.2f\n", $selisih);
    $ok = $selisih > 3.0 && $selisih < 5.0;
    $lulus = $lulus && $ok;
    echo "  [" . ($ok ? 'OK   ' : 'GAGAL') . "] metode lama mencatat modal terlalu rendah ~\$3.91\n";

    echo "\n=== UJI 3: rata-rata tidak berubah karena penjualan sebagian ===\n";
    Trade::query()->delete();
    Trade::create(['symbol' => 'XUSDT', 'type' => 'BUY', 'amount' => 1000, 'price' => 2.0,
        'total' => 2000, 'trade_time' => '2026-01-01 00:00:00']);
    Trade::create(['symbol' => 'XUSDT', 'type' => 'SELL', 'amount' => 400, 'price' => 3.0,
        'total' => 1200, 'trade_time' => '2026-01-02 00:00:00']);

    $b = $svc->forAsset('X', $prices);
    periksa('sisa posisi setelah jual sebagian', $b['amount'], 600.0, $lulus, 2);
    periksa('rata-rata tetap', $b['avg_price_usdt'], 2.0, $lulus);
    periksa('modal menyusut sebanding', $b['cost_usdt'], 1200.0, $lulus, 2);

    echo "\n=== UJI 4: DEPOSIT ikut menambah modal, WITHDRAW menguranginya ===\n";
    Trade::query()->delete();
    Trade::create(['symbol' => 'YUSDT', 'type' => 'DEPOSIT', 'amount' => 100, 'price' => 5.0,
        'total' => 500, 'trade_time' => '2026-01-01 00:00:00']);
    Trade::create(['symbol' => 'YUSDT', 'type' => 'WITHDRAW', 'amount' => 40, 'price' => 5.0,
        'total' => 200, 'trade_time' => '2026-01-02 00:00:00']);

    $b = $svc->forAsset('Y', $prices);
    periksa('sisa posisi', $b['amount'], 60.0, $lulus, 2);
    periksa('rata-rata', $b['avg_price_usdt'], 5.0, $lulus);
    periksa('modal', $b['cost_usdt'], 300.0, $lulus, 2);

    echo "\n=== UJI 5: harga berkuota IDR dikonversi ke USDT ===\n";
    Trade::query()->delete();
    Trade::create(['symbol' => 'ZIDR', 'type' => 'BUY', 'amount' => 100, 'price' => 17607.0,
        'total' => 1760700, 'trade_time' => '2026-01-01 00:00:00']);

    $b = $svc->forAsset('Z', $prices);
    periksa('rata-rata (17607 / kurs 17607)', $b['avg_price_usdt'], 1.0, $lulus);

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    DB::rollBack();
    echo "\n(Data uji di-rollback.)\n";
}
