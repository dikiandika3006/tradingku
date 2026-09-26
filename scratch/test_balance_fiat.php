<?php
/**
 * Uji perintah portfolio:balance-fiat.
 *
 * Saldo fiat tidak boleh minus di titik mana pun sepanjang riwayat, bukan sekadar
 * ditambal di akhir. Perintahnya juga harus idempoten dan tidak mengubah PNL.
 *
 * Semua di dalam transaksi dan di-rollback.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Trade;
use App\Services\TokocryptoService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class FakeTokocryptoService extends TokocryptoService
{
    public function __construct() {}
    public function getAllPrices(): array { return ['USDTIDR' => 17615.0, 'FETUSDT' => 0.16]; }
    public function getPortfolio(): array { return ['usdt_idr_rate' => 17615.0, 'is_live' => false, 'assets' => []]; }
}

$lulus = true;

function periksa(string $label, $dapat, $harus, bool &$lulus): void
{
    $ok = is_numeric($harus) ? abs((float)$dapat - (float)$harus) < 0.01 : $dapat === $harus;
    $lulus = $lulus && $ok;
    printf("  [%s] %-44s %18s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label,
        is_numeric($dapat) ? number_format((float)$dapat, 2) : var_export($dapat, true),
        is_numeric($harus) ? number_format((float)$harus, 2) : var_export($harus, true));
}

/** Saldo IDR hasil rekonstruksi jurnal, sama caranya dengan TokocryptoService. */
function saldoIdr(): float
{
    $saldo = 0.0;
    foreach (Trade::orderBy('trade_time')->orderBy('id')->get() as $t) {
        $type = strtoupper(trim($t->type));
        $total = (float)$t->total ?: (float)$t->price * (float)$t->amount;
        [$base, $quote] = Trade::splitSymbol($t->symbol);

        if ($type === 'DEPOSIT')       { if ($base === 'IDR') $saldo += (float)$t->amount; }
        elseif ($type === 'WITHDRAW')  { if ($base === 'IDR') $saldo -= (float)$t->amount; }
        elseif ($quote === 'IDR')      { $saldo += ($type === 'BUY' ? -$total : $total); }
    }
    return $saldo;
}

/** Saldo IDR terendah yang pernah terjadi sepanjang riwayat. */
function saldoIdrTerendah(): float
{
    $saldo = 0.0; $min = 0.0;
    foreach (Trade::orderBy('trade_time')->orderBy('id')->get() as $t) {
        $type = strtoupper(trim($t->type));
        $total = (float)$t->total ?: (float)$t->price * (float)$t->amount;
        [$base, $quote] = Trade::splitSymbol($t->symbol);

        if ($type === 'DEPOSIT')       { if ($base === 'IDR') $saldo += (float)$t->amount; }
        elseif ($type === 'WITHDRAW')  { if ($base === 'IDR') $saldo -= (float)$t->amount; }
        elseif ($quote === 'IDR')      { $saldo += ($type === 'BUY' ? -$total : $total); }

        $min = min($min, $saldo);
    }
    return $min;
}

app()->instance(TokocryptoService::class, new FakeTokocryptoService());

DB::beginTransaction();

try {
    Trade::query()->delete();

    // Dua gelombang pembelian berkuota IDR tanpa satu pun setoran rupiah dijurnal.
    // Ini meniru kondisi nyata: saldo IDR terjun ke minus.
    Trade::create(['symbol' => 'USDTIDR', 'type' => 'BUY', 'amount' => 100, 'price' => 17894,
        'total' => 1789400, 'trade_time' => '2026-07-16 11:00:00']);
    Trade::create(['symbol' => 'FETIDR', 'type' => 'BUY', 'amount' => 850, 'price' => 2878,
        'total' => 2446300, 'trade_time' => '2026-08-02 09:00:00']);
    // Penjualan mengembalikan sebagian rupiah
    Trade::create(['symbol' => 'FETIDR', 'type' => 'SELL', 'amount' => 400, 'price' => 3000,
        'total' => 1200000, 'trade_time' => '2026-08-20 09:00:00']);

    $awal = saldoIdr();
    echo "=== Kondisi awal ===\n";
    periksa('saldo IDR minus', $awal, -3035700.0, $lulus);
    // Titik terendah LEBIH dalam daripada saldo akhir, karena penjualan di Agustus
    // mengembalikan Rp 1.200.000 setelah saldo sempat menyentuh dasarnya.
    periksa('titik terendah minus', saldoIdrTerendah(), -4235700.0, $lulus);
    $jumlahAwal = Trade::count();

    echo "\n=== Mode usulan (tanpa --apply) tidak boleh mengubah apa pun ===\n";
    Artisan::call('portfolio:balance-fiat', ['--currency' => 'IDR']);
    $out = Artisan::output();
    periksa('jumlah transaksi tidak bertambah', Trade::count(), $jumlahAwal, $lulus);
    periksa('saldo belum berubah', saldoIdr(), $awal, $lulus);
    $adaPeringatan = str_contains($out, '--apply');
    $lulus = $lulus && $adaPeringatan;
    echo "  [" . ($adaPeringatan ? 'OK   ' : 'GAGAL') . "] mengingatkan bahwa ini baru usulan\n";

    echo "\n=== Terapkan ===\n";
    Artisan::call('portfolio:balance-fiat', ['--currency' => 'IDR', '--apply' => true]);
    echo Artisan::output();

    // Sasarannya BUKAN memaksa saldo akhir jadi nol, melainkan memastikan saldo
    // tidak pernah minus. Sisa Rp 1.200.000 adalah hasil penjualan Agustus yang
    // menurut jurnal memang masih mengendap.
    periksa('tidak pernah minus sepanjang riwayat', saldoIdrTerendah(), 0.0, $lulus);
    periksa('saldo akhir = kekurangan + saldo lama', saldoIdr(), 4235700.0 + $awal, $lulus);
    periksa('saldo akhir tidak minus lagi', max(0.0, -saldoIdr()), 0.0, $lulus);

    echo "\n=== Setoran dibuat di waktu yang tepat, bukan ditumpuk di akhir ===\n";
    $setoran = Trade::where('type', 'DEPOSIT')->where('symbol', 'IDR')
        ->orderBy('trade_time')->get();
    periksa('jumlah setoran penyeimbang', $setoran->count(), 2, $lulus);
    foreach ($setoran as $s) {
        printf("     %s  Rp %s\n", $s->trade_time, number_format((float)$s->amount, 2, ',', '.'));
    }
    $tepatWaktu = $setoran->first() && str_starts_with((string)$setoran->first()->trade_time, '2026-07-16');
    $lulus = $lulus && $tepatWaktu;
    echo "  [" . ($tepatWaktu ? 'OK   ' : 'GAGAL') . "] setoran pertama bertanggal sebelum pembelian pertama\n";

    echo "\n=== Idempoten: jalankan lagi tidak menambah apa-apa ===\n";
    $sebelum = Trade::count();
    Artisan::call('portfolio:balance-fiat', ['--currency' => 'IDR', '--apply' => true]);
    $out2 = Artisan::output();
    periksa('jumlah transaksi tetap', Trade::count(), $sebelum, $lulus);
    $bersih = str_contains($out2, 'tidak perlu penyeimbang') || str_contains($out2, 'sudah seimbang');
    $lulus = $lulus && $bersih;
    echo "  [" . ($bersih ? 'OK   ' : 'GAGAL') . "] melaporkan saldo sudah seimbang\n";

    echo "\n=== Tidak mengusik PNL: DEPOSIT bukan BUY/SELL ===\n";
    $jumlahBuySell = Trade::whereIn('type', ['BUY', 'SELL'])->count();
    periksa('transaksi BUY/SELL tetap 3', $jumlahBuySell, 3, $lulus);

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    DB::rollBack();
    echo "\n(Data uji di-rollback.)\n";
}
