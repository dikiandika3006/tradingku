<?php
/**
 * Uji penjagaan Browser-Bridge.
 *
 * 1. Query yang dieksekusi di dalam transaksi lalu di-rollback TIDAK boleh
 *    masuk antrean sinkronisasi.
 * 2. Query berbahaya (DELETE tanpa WHERE, DDL, multi-statement) harus ditolak
 *    sebelum menyentuh database produksi.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\SettingController;
use App\Models\Trade;
use Illuminate\Support\Facades\DB;

$logPath = storage_path('app/pending_queries.json');
$backup  = file_exists($logPath) ? file_get_contents($logPath) : null;

$lulus = true;

function antrean(string $path): array
{
    return file_exists($path) ? (json_decode(file_get_contents($path), true) ?? []) : [];
}

try {
    // ---------------------------------------------------------------
    echo "=== UJI 1: query yang di-rollback tidak masuk antrean ===\n";
    // ---------------------------------------------------------------
    file_put_contents($logPath, json_encode([], JSON_PRETTY_PRINT));
    $awal = count(antrean($logPath));

    try {
        DB::transaction(function () {
            Trade::create([
                'symbol' => 'DUMMYUSDT', 'type' => 'BUY', 'amount' => 1,
                'price' => 1, 'total' => 1, 'trade_time' => '2026-01-01 00:00:00',
            ]);
            Trade::where('symbol', 'DUMMYUSDT')->delete();

            throw new \RuntimeException('sengaja dibatalkan');
        });
    } catch (\RuntimeException $e) {
        // diharapkan
    }

    $setelah = count(antrean($logPath));
    printf("  Antrean sebelum: %d, sesudah rollback: %d\n", $awal, $setelah);
    if ($setelah === $awal) {
        echo "  [OK]    Query yang di-rollback tidak tercatat\n";
    } else {
        echo "  [GAGAL] Masih ada {$setelah} query yang bocor ke antrean\n";
        $lulus = false;
    }

    // ---------------------------------------------------------------
    echo "\n=== UJI 2: transaksi yang commit TETAP tercatat ===\n";
    // ---------------------------------------------------------------
    file_put_contents($logPath, json_encode([], JSON_PRETTY_PRINT));

    DB::transaction(function () {
        Trade::create([
            'symbol' => 'DUMMYUSDT', 'type' => 'BUY', 'amount' => 1,
            'price' => 1, 'total' => 1, 'trade_time' => '2026-01-01 00:00:00',
        ]);
    });

    $tercatat = count(antrean($logPath));
    printf("  Query tercatat: %d\n", $tercatat);
    if ($tercatat > 0) {
        echo "  [OK]    Perubahan sah tetap masuk antrean\n";
    } else {
        echo "  [GAGAL] Perubahan sah ikut hilang — penjagaan terlalu ketat\n";
        $lulus = false;
    }

    // Bersihkan jejak uji
    Trade::where('symbol', 'DUMMYUSDT')->forceDelete();

    // ---------------------------------------------------------------
    echo "\n=== UJI 3: penyaring query berbahaya ===\n";
    // ---------------------------------------------------------------
    $controller = app(SettingController::class);
    $periksa = new ReflectionMethod($controller, 'rejectionReasonFor');
    $periksa->setAccessible(true);

    $kasus = [
        // [sql, harus_ditolak?]
        ['delete from `trades`',                                              true],
        ['DELETE FROM trades',                                                true],
        ['update `trades` set `price` = 0',                                   true],
        ['drop table `trades`',                                               true],
        ['truncate table `trades`',                                           true],
        ["insert into `trades` (`symbol`) values ('X'); drop table `users`",   true],
        ["select * from `trades`",                                            true],
        ["delete from `logs` where `id` = 1",                                 true],
        // Yang sah harus lolos
        ["insert into `trades` (`symbol`, `type`) values ('BTCUSDT', 'BUY')",  false],
        ["delete from `trades` where `id` = 42",                              false],
        ["update `settings` set `value` = '1' where `key` = 'x'",             false],
    ];

    foreach ($kasus as [$sql, $harusDitolak]) {
        $alasan   = $periksa->invoke($controller, $sql);
        $ditolak  = $alasan !== null;
        $cocok    = $ditolak === $harusDitolak;
        $lulus    = $lulus && $cocok;

        printf("  [%s] %-58s -> %s\n",
            $cocok ? 'OK   ' : 'GAGAL',
            mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 58),
            $ditolak ? "DITOLAK ({$alasan})" : 'lolos'
        );
    }

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    // Kembalikan antrean seperti semula
    if ($backup !== null) {
        file_put_contents($logPath, $backup, LOCK_EX);
    } else {
        @unlink($logPath);
    }
    echo "\n(Antrean pending_queries.json dikembalikan seperti sebelum uji.)\n";
}
