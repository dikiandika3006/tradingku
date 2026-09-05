<?php
/**
 * Diagnosa komposisi jurnal transaksi.
 *
 * Dijalankan di server produksi untuk melihat bagaimana setoran dicatat,
 * sebelum memperbaiki penanganan DEPOSIT/WITHDRAW.
 *
 * Hanya menampilkan agregat (tipe, simbol, jumlah transaksi, total) —
 * tidak ada data sensitif.
 *
 * Taruh di root Laravel, jalankan:
 *   /opt/alt/php83/usr/bin/php /path/ke/app/cek_komposisi_trade.php
 */

// Cari root Laravel, supaya skrip ini jalan baik dari root maupun dari subfolder
$root = __DIR__;
while (!file_exists($root . '/vendor/autoload.php') && dirname($root) !== $root) {
    $root = dirname($root);
}
if (!file_exists($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Tidak menemukan vendor/autoload.php. Taruh skrip ini di dalam folder project Laravel.\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
$app = require_once $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Trade;
use App\Services\TokocryptoService;

echo "===== " . date('Y-m-d H:i:s') . " =====\n\n";

echo "--- KOMPOSISI JURNAL (per tipe + simbol) ---\n";
printf("%-10s %-14s %7s %18s %18s\n", 'TIPE', 'SIMBOL', 'JML', 'TOTAL AMOUNT', 'TOTAL NILAI');
echo str_repeat('-', 72) . "\n";

$rows = Trade::selectRaw('type, symbol, COUNT(*) AS n, SUM(amount) AS amt, SUM(total) AS tot')
    ->groupBy('type', 'symbol')
    ->orderBy('type')
    ->orderBy('symbol')
    ->get();

foreach ($rows as $r) {
    printf("%-10s %-14s %7d %18s %18s\n",
        $r->type, $r->symbol, $r->n,
        number_format((float)$r->amt, 6),
        number_format((float)$r->tot, 2));
}

echo "\n--- SALDO HASIL REKONSTRUKSI (yang dipakai halaman Portofolio) ---\n";
$portfolio = app(TokocryptoService::class)->getPortfolio();
printf("%-14s %20s %14s %16s\n", 'ASET', 'SALDO', 'HARGA', 'NILAI USDT');
echo str_repeat('-', 68) . "\n";

$total = 0.0;
foreach ($portfolio['assets'] ?? [] as $a) {
    $total += (float)$a['value_usdt'];
    printf("%-14s %20s %14s %16s\n",
        $a['asset'],
        number_format((float)$a['total'], 6),
        number_format((float)$a['price'], 6),
        number_format((float)$a['value_usdt'], 2));
}
printf("%-14s %20s %14s %16s\n", 'TOTAL', '', '', number_format($total, 2));
echo "\nSumber saldo: " . ($portfolio['assets'][0]['source'] ?? '-') . "\n";
echo "Kurs USDT/IDR: " . number_format($portfolio['usdt_idr_rate'] ?? 0, 2) . "\n";

echo "\n--- CEK TUMPANG TINDIH DEPOSIT vs BUY ---\n";
$depositSymbols = Trade::whereIn('type', ['DEPOSIT', 'WITHDRAW'])->distinct()->pluck('symbol');

if ($depositSymbols->isEmpty()) {
    echo "Tidak ada transaksi DEPOSIT/WITHDRAW sama sekali.\n";
} else {
    foreach ($depositSymbols as $sym) {
        $dep  = Trade::where('symbol', $sym)->whereIn('type', ['DEPOSIT', 'WITHDRAW'])->sum('amount');
        $buy  = Trade::where('symbol', $sym)->where('type', 'BUY')->sum('amount');
        $sell = Trade::where('symbol', $sym)->where('type', 'SELL')->sum('amount');

        printf("  %-14s DEPOSIT/WITHDRAW=%s  BUY=%s  SELL=%s  %s\n",
            $sym,
            number_format((float)$dep, 6),
            number_format((float)$buy, 6),
            number_format((float)$sell, 6),
            $buy > 0 ? '<-- BERPOTENSI DOBEL' : '');
    }
}

echo "\n===== selesai =====\n";
