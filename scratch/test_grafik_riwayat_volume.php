<?php
/**
 * Uji grafik riwayat volume di halaman heatmap.
 *
 * Memastikan data yang dikirim ke Chart.js urut kiri-ke-kanan, berisi kelima
 * jendela waktu, dan halamannya tetap aman saat rekamannya masih kurang dari
 * dua hari.
 *
 * Semua di dalam transaksi dan di-rollback.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SpotVolumeSnapshot;
use App\Services\SpotVolumeService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

// SQLite di memori: uji ini tidak bergantung MySQL yang menyala, dan tidak
// menyentuh database mana pun.
Config::set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
Config::set('database.default', 'uji');
DB::purge('uji');

Schema::create('spot_volume_snapshots', function ($t) {
    $t->id();
    $t->string('symbol'); $t->string('exchange_name'); $t->string('pair')->nullable();
    $t->decimal('price_usd', 20, 8)->nullable();
    foreach (['1h', '4h', '12h', '24h', '1w'] as $w) {
        $t->decimal("volume_usd_{$w}", 24, 2)->default(0);
        $t->decimal("volume_change_percent_{$w}", 12, 4)->nullable();
    }
    $t->dateTime('captured_at'); $t->date('captured_date'); $t->timestamps();
});

// Bursa dipalsukan supaya uji tidak bergantung jaringan
class FakeSpotVolumeService extends SpotVolumeService
{
    public function aggregate(string $symbol = 'BTC', bool $useCache = true): array
    {
        return ['ok' => false, 'message' => 'uji: pengambilan live dilewati',
                'exchanges' => [], 'totals' => array_fill_keys(array_keys(self::WINDOWS), 0.0),
                'changes' => array_fill_keys(array_keys(self::WINDOWS), null),
                'price_usd' => null, 'failures' => [], 'fetched_at' => null];
    }
}
app()->instance(SpotVolumeService::class, new FakeSpotVolumeService());
Http::fake();

$lulus = true;

function periksa(string $label, $dapat, $harus, bool &$lulus): void
{
    $ok = is_float($harus) ? (is_numeric($dapat) && abs((float)$dapat - $harus) < 0.01) : $dapat === $harus;
    $lulus = $lulus && $ok;
    printf("  [%s] %-44s %18s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label,
        is_bool($dapat) ? var_export($dapat, true) : (string)$dapat,
        is_bool($harus) ? var_export($harus, true) : (string)$harus);
}

/** Tiru blok @php di view yang menyusun data grafik. */
function dataGrafik($daily, array $timeframes): array
{
    $urut = $daily->reverse()->values();
    $out = ['label' => $urut->map(fn($d) => \Carbon\Carbon::parse($d->captured_date)->translatedFormat('j M'))->all()];
    foreach ($timeframes as $tf) {
        $out['nilai'][$tf] = $urut->map(fn($d) => (float)$d->{"volume_usd_{$tf}"})->all();
    }
    return $out;
}

DB::beginTransaction();

try {
    SpotVolumeSnapshot::where('symbol', 'UJIBTC')->delete();

    // Lima hari rekaman, nilai 7 hari menanjak lalu turun seperti di grafik contoh
    $nilai7h = [20.5e9, 21.2e9, 19.4e9, 19.6e9, 19.1e9];
    foreach ($nilai7h as $i => $v) {
        $tgl = \Carbon\Carbon::parse('2026-10-15')->addDays($i);
        // Dua bursa per hari, untuk memastikan penjumlahannya benar
        foreach (['Binance' => 0.7, 'Bybit' => 0.3] as $bursa => $porsi) {
            SpotVolumeSnapshot::create([
                'symbol' => 'UJIBTC', 'exchange_name' => $bursa, 'pair' => 'BTCUSDT',
                'price_usd' => 84000, 'volume_usd_1h' => 50e6 * $porsi,
                'volume_usd_4h' => 200e6 * $porsi, 'volume_usd_12h' => 600e6 * $porsi,
                'volume_usd_24h' => 2.5e9 * $porsi, 'volume_usd_1w' => $v * $porsi,
                'captured_at' => $tgl->copy()->setTime(0, 30), 'captured_date' => $tgl->toDateString(),
            ]);
        }
    }

    // ---------------------------------------------------------------
    echo "=== UJI 1: data grafik urut kiri ke kanan ===\n";
    // ---------------------------------------------------------------
    $daily = SpotVolumeSnapshot::dailyTotals('UJIBTC', 14);
    $tf = SpotVolumeSnapshot::TIMEFRAMES;
    $g = dataGrafik($daily, $tf);

    periksa('jumlah titik', count($g['label']), 5, $lulus);
    // Locale aplikasi 'en', jadi nama bulannya Inggris — konsisten dengan
    // tabel riwayat yang sudah menampilkan "29 Sep 2026".
    periksa('label pertama = hari TERLAMA', $g['label'][0], '15 Oct', $lulus);
    periksa('label terakhir = hari TERBARU', $g['label'][4], '19 Oct', $lulus);
    // Tabel menampilkan terbaru dulu; grafik harus kebalikannya
    periksa('tabel memang terbaru dulu',
        \Carbon\Carbon::parse($daily->first()->captured_date)->translatedFormat('j M'), '19 Oct', $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 2: nilai dijumlahkan dari seluruh bursa ===\n";
    // ---------------------------------------------------------------
    periksa('titik ke-1 jendela 7 hari', $g['nilai']['1w'][0], 20.5e9, $lulus);
    periksa('titik puncak (hari ke-2)', $g['nilai']['1w'][1], 21.2e9, $lulus);
    periksa('titik terakhir', $g['nilai']['1w'][4], 19.1e9, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 3: kelima jendela tersedia untuk tombol ===\n";
    // ---------------------------------------------------------------
    foreach ($tf as $w) {
        periksa("jendela {$w} punya 5 titik", count($g['nilai'][$w] ?? []), 5, $lulus);
    }
    periksa('jendela 24 jam terjumlah benar', $g['nilai']['24h'][0], 2.5e9, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 4: halaman aman saat rekaman masih sedikit ===\n";
    // ---------------------------------------------------------------
    // Sisakan hari pertama saja. Perbandingan "> tanggal" tidak dipakai karena
    // SQLite menyimpan kolom date berikut jamnya, sehingga hari itu ikut terhapus.
    SpotVolumeSnapshot::where('symbol', 'UJIBTC')
        ->whereNotIn('captured_date', ['2026-10-15', '2026-10-15 00:00:00'])
        ->delete();

    $sedikit = dataGrafik(SpotVolumeSnapshot::dailyTotals('UJIBTC', 14), $tf);
    periksa('tinggal satu titik', count($sedikit['label']), 1, $lulus);
    periksa('kanvas disembunyikan di view', count($sedikit['label']) >= 2, false, $lulus);

    SpotVolumeSnapshot::where('symbol', 'UJIBTC')->delete();
    $kosong = dataGrafik(SpotVolumeSnapshot::dailyTotals('UJIBTC', 14), $tf);
    periksa('tanpa rekaman tetap tidak error', count($kosong['label']), 0, $lulus);

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    DB::rollBack();
    echo "\n(Data uji di-rollback.)\n";
}
