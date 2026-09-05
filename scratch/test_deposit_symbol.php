<?php
/**
 * Uji penanganan DEPOSIT / WITHDRAW bersimbol pasangan.
 *
 * Sebelum perbaikan: DEPOSIT "USDTIDR" menciptakan aset fiktif bernama
 * "USDTIDR" yang tidak punya harga pasar, sehingga tampil rugi -100%.
 *
 * Sesudah: simbol dipecah seperti BUY (base = USDT), tapi sisi quote TIDAK
 * didebit karena dananya datang dari luar portofolio.
 *
 * Semua di dalam transaksi dan di-rollback.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Trade;
use App\Services\TokocryptoService;
use Illuminate\Support\Facades\DB;

$lulus = true;

function periksa(string $label, $dapat, $harus, bool &$lulus): void
{
    $ok = is_float($harus) || is_int($harus)
        ? (is_numeric($dapat) && abs((float)$dapat - (float)$harus) < 0.000001)
        : $dapat === $harus;

    $lulus = $lulus && $ok;
    printf("  [%s] %-46s %-22s (harusnya %s)\n",
        $ok ? 'OK   ' : 'GAGAL',
        $label,
        is_bool($dapat) ? ($dapat ? 'true' : 'false') : (string)($dapat ?? 'null'),
        is_bool($harus) ? ($harus ? 'true' : 'false') : (string)($harus ?? 'null'));
}

// ---------------------------------------------------------------
echo "=== UJI 1: pemecahan simbol ===\n";
// ---------------------------------------------------------------
$kasus = [
    ['USDTIDR',  'USDT', 'IDR'],   // setoran: beli USDT pakai IDR
    ['FETIDR',   'FET',  'IDR'],
    ['BTCUSDT',  'BTC',  'USDT'],
    ['LUMIABIDR','LUMIA','BIDR'],
    ['USDT',     'USDT', null],    // tanpa pasangan
    ['IDR',      'IDR',  null],
];

foreach ($kasus as [$sym, $baseHarus, $quoteHarus]) {
    [$base, $quote] = Trade::splitSymbol($sym);
    $ok = ($base === $baseHarus && $quote === $quoteHarus);
    $lulus = $lulus && $ok;
    printf("  [%s] %-12s -> base=%-6s quote=%-6s (harusnya %s / %s)\n",
        $ok ? 'OK   ' : 'GAGAL', $sym, $base, $quote ?? '-', $baseHarus, $quoteHarus ?? '-');
}

DB::beginTransaction();

try {
    Trade::query()->delete();

    // ---------------------------------------------------------------
    echo "\n=== UJI 2: scope forBaseAsset tidak salah tangkap ===\n";
    // ---------------------------------------------------------------
    Trade::create(['symbol' => 'FETIDR',    'type' => 'BUY', 'amount' => 850, 'price' => 2878,
        'total' => 2446432, 'trade_time' => '2026-07-16 10:00:00']);
    Trade::create(['symbol' => 'FETCHUSDT', 'type' => 'BUY', 'amount' => 10, 'price' => 1,
        'total' => 10, 'trade_time' => '2026-07-16 10:00:00']);

    $fet = Trade::forBaseAsset('FET')->pluck('symbol')->all();
    periksa('FET hanya menangkap FETIDR', implode(',', $fet), 'FETIDR', $lulus);
    periksa('FETCHUSDT tidak ikut tertangkap', in_array('FETCHUSDT', $fet), false, $lulus);

    Trade::query()->delete();

    // ---------------------------------------------------------------
    echo "\n=== UJI 3: DEPOSIT USDTIDR menambah saldo USDT ===\n";
    // ---------------------------------------------------------------
    Trade::create(['symbol' => 'USDTIDR', 'type' => 'DEPOSIT', 'amount' => 141.36,
        'price' => 17894, 'total' => 2529524, 'trade_time' => '2026-07-16 10:00:00']);

    $service = app(TokocryptoService::class);
    $saldo = [];
    foreach ($service->getPortfolio()['assets'] ?? [] as $a) {
        $saldo[$a['asset']] = (float)$a['total'];
    }

    periksa('saldo USDT bertambah', $saldo['USDT'] ?? 0.0, 141.36, $lulus);
    periksa('aset fiktif USDTIDR tidak ada', isset($saldo['USDTIDR']), false, $lulus);
    periksa('IDR tidak didebit (dana dari luar)', isset($saldo['IDR']), false, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 4: BUY tetap mendebit sisi quote ===\n";
    // ---------------------------------------------------------------
    Trade::create(['symbol' => 'FETIDR', 'type' => 'BUY', 'amount' => 850, 'price' => 2878,
        'total' => 2446432, 'trade_time' => '2026-07-17 10:00:00']);

    $saldo = [];
    foreach ($service->getPortfolio()['assets'] ?? [] as $a) {
        $saldo[$a['asset']] = (float)$a['total'];
    }

    periksa('saldo FET bertambah', $saldo['FET'] ?? 0.0, 850.0, $lulus);
    periksa('IDR terdebit oleh BUY', $saldo['IDR'] ?? 0.0, -2446432.0, $lulus);
    periksa('USDT tidak terpengaruh BUY berkuota IDR', $saldo['USDT'] ?? 0.0, 141.36, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 5: WITHDRAW USDTIDR mengurangi saldo USDT ===\n";
    // ---------------------------------------------------------------
    Trade::create(['symbol' => 'USDTIDR', 'type' => 'WITHDRAW', 'amount' => 41.36,
        'price' => 17894, 'total' => 740095, 'trade_time' => '2026-07-18 10:00:00']);

    $saldo = [];
    foreach ($service->getPortfolio()['assets'] ?? [] as $a) {
        $saldo[$a['asset']] = (float)$a['total'];
    }

    periksa('saldo USDT berkurang (141.36 - 41.36)', $saldo['USDT'] ?? 0.0, 100.0, $lulus);
    periksa('IDR tidak berubah oleh WITHDRAW', $saldo['IDR'] ?? 0.0, -2446432.0, $lulus);

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    DB::rollBack();
    echo "\n(Data uji di-rollback.)\n";
}
