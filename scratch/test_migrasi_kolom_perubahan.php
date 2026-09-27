<?php
/**
 * Uji migration penambah kolom perubahan volume.
 *
 * Meniru kondisi produksi: tabel sudah terlanjur dibuat TANPA kolom perubahan.
 * Migration harus menambahkannya, dan aman dijalankan berulang.
 *
 * Kolom dikembalikan seperti semula di akhir.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;

$lulus = true;
$berkas = __DIR__ . '/../database/migrations/2026_09_27_000000_add_volume_change_columns_to_spot_volume_snapshots_table.php';

$kolomPerubahan = [
    'volume_change_percent_1h',
    'volume_change_percent_4h',
    'volume_change_percent_12h',
    'volume_change_percent_24h',
    'volume_change_percent_1w',
];

function adaBerapa(array $perlu): int
{
    $ada = Schema::getColumnListing('spot_volume_snapshots');
    return count(array_intersect($perlu, $ada));
}

function periksa(string $label, $dapat, $harus, bool &$lulus): void
{
    $ok = $dapat === $harus;
    $lulus = $lulus && $ok;
    printf("  [%s] %-46s %6s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label,
        is_bool($dapat) ? var_export($dapat, true) : $dapat,
        is_bool($harus) ? var_export($harus, true) : $harus);
}

$migration = require $berkas;

try {
    echo "=== Kondisi awal ===\n";
    periksa('kolom perubahan terpasang', adaBerapa($kolomPerubahan), 5, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== Tiru kondisi produksi: kolomnya dibuang ===\n";
    // ---------------------------------------------------------------
    Schema::table('spot_volume_snapshots', function ($t) use ($kolomPerubahan) {
        $t->dropColumn($kolomPerubahan);
    });
    periksa('kolom perubahan hilang', adaBerapa($kolomPerubahan), 0, $lulus);
    periksa('kolom volume tetap utuh', Schema::hasColumn('spot_volume_snapshots', 'volume_usd_24h'), true, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== Jalankan migration ===\n";
    // ---------------------------------------------------------------
    $migration->up();
    periksa('kelima kolom ditambahkan', adaBerapa($kolomPerubahan), 5, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== Jalankan lagi: harus aman, bukan error ===\n";
    // ---------------------------------------------------------------
    $migration->up();
    periksa('tetap 5 kolom, tidak dobel', adaBerapa($kolomPerubahan), 5, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== Baris baru bisa ditulis dengan kolom itu ===\n";
    // ---------------------------------------------------------------
    // Inilah query yang tadi gagal di produksi
    $snap = App\Models\SpotVolumeSnapshot::create([
        'symbol' => 'UJICOBA', 'exchange_name' => 'Binance', 'pair' => 'BTCUSDT',
        'price_usd' => 84402.15, 'volume_usd_1h' => 16526039.21,
        'volume_usd_4h' => 76398607.5, 'volume_usd_12h' => 337828596.87,
        'volume_usd_24h' => 640269708.07, 'volume_usd_1w' => 10912600962.81,
        'volume_change_percent_1h' => -11.8675, 'volume_change_percent_4h' => -47.8461,
        'volume_change_percent_12h' => 11.7006, 'volume_change_percent_24h' => -55.9488,
        'volume_change_percent_1w' => 31.3838,
        'captured_at' => now(), 'captured_date' => now()->toDateString(),
    ]);

    $ulang = App\Models\SpotVolumeSnapshot::find($snap->id);
    periksa('tersimpan tanpa error', $ulang !== null, true, $lulus);
    periksa('nilai perubahan 1 jam terbaca', round($ulang->volume_change_percent_1h, 4), -11.8675, $lulus);
    periksa('nilai perubahan 7 hari terbaca', round($ulang->volume_change_percent_1w, 4), 31.3838, $lulus);

    $ulang->delete();
    periksa('baris uji dibersihkan', App\Models\SpotVolumeSnapshot::where('symbol', 'UJICOBA')->count(), 0, $lulus);

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    // Pastikan skema kembali lengkap apa pun yang terjadi
    $migration->up();
    App\Models\SpotVolumeSnapshot::where('symbol', 'UJICOBA')->delete();
    echo "\n(Skema dikembalikan lengkap: " . adaBerapa($kolomPerubahan) . "/5 kolom.)\n";
}
