<?php
/**
 * Uji proyeksi modal terhadap angka spreadsheet yang sebenarnya.
 *
 * PENTING: memakai SQLite di memori, BUKAN database aplikasi. Aman dijalankan
 * meski .env sedang mengarah ke produksi.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\MonthlyPortfolioSnapshot;
use App\Models\Setting;
use App\Services\CapitalProjectionService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Config::set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
Config::set('database.default', 'uji');
DB::purge('uji');

if (config('database.default') !== 'uji' || config('database.connections.uji.database') !== ':memory:') {
    fwrite(STDERR, "BERHENTI: koneksi uji gagal dialihkan.\n");
    exit(1);
}
echo "Koneksi uji: SQLite di memori — database produksi TIDAK tersentuh.\n\n";

Schema::create('settings', function ($t) { $t->id(); $t->string('key')->unique(); $t->text('value')->nullable(); $t->timestamps(); });
Schema::create('monthly_portfolio_snapshots', function ($t) {
    $t->id(); $t->string('year_month')->unique(); $t->dateTime('cut_off_date')->nullable();
    $t->decimal('ending_valuation_usdt', 20, 4)->default(0); $t->decimal('ending_valuation_idr', 20, 2)->default(0);
    $t->boolean('is_finalized')->default(false); $t->timestamps();
});

$lulus = true;
function periksa(string $label, $dapat, $harus, bool &$lulus, float $toleransi = 1.0): void
{
    $ok = is_numeric($harus) ? abs((float)$dapat - (float)$harus) < $toleransi : $dapat === $harus;
    $lulus = $lulus && $ok;
    printf("  [%s] %-40s %18s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label,
        is_numeric($dapat) ? number_format((float)$dapat, 2) : (string)$dapat,
        is_numeric($harus) ? number_format((float)$harus, 2) : (string)$harus);
}

$svc = app(CapitalProjectionService::class);

// ---------------------------------------------------------------
echo "=== UJI 1: nilai bawaan cocok dengan spreadsheet ===\n";
// ---------------------------------------------------------------
$h = $svc->build();
$r = collect($h['rows'])->keyBy('year_month');

// Empat baris pertama, dibandingkan dengan gambar spreadsheet
$acuan = [
    '2026-09' => [22000000.00, 0.00, 9.80, 2156000.00, 24156000.00, 4831200, 19324800],
    '2026-10' => [24156000.00, 3000000.00, 3.00, 724680.00, 27880680.00, 8364204, 19516476],
    '2026-11' => [27880680.00, 3000000.00, 3.00, 836420.40, 31717100.40, 12686840, 19030260],
    '2026-12' => [31717100.40, 3000000.00, 3.00, 951513.01, 35668613.41, 17834307, 17834307],
];

foreach ($acuan as $ym => [$modal, $topup, $persen, $retIdr, $akhir, $btc, $alt]) {
    $x = $r[$ym];
    periksa("{$ym} modal awal",  $x['modal_awal'],  $modal, $lulus);
    periksa("{$ym} top up",      $x['top_up'],      $topup, $lulus);
    periksa("{$ym} return %",    $x['return_persen'], $persen, $lulus, 0.001);
    periksa("{$ym} return Rp",   $x['return_idr'],  $retIdr, $lulus);
    periksa("{$ym} saldo akhir", $x['saldo_akhir'], $akhir, $lulus);
    periksa("{$ym} alokasi BTC", $x['alokasi_btc'], $btc, $lulus);
    periksa("{$ym} alokasi Alt", $x['alokasi_alt'], $alt, $lulus);
}

// ---------------------------------------------------------------
echo "\n=== UJI 2: fase alokasi berlaku sampai diganti ===\n";
// ---------------------------------------------------------------
periksa('2027-06 masih 50% BTC', $r['2027-06']['btc_persen'], 50.0, $lulus, 0.001);
periksa('2027-12 masih 50% BTC', $r['2027-12']['btc_persen'], 50.0, $lulus, 0.001);
periksa('2028-01 berubah 60% BTC', $r['2028-01']['btc_persen'], 60.0, $lulus, 0.001);
periksa('2028-06 tetap 60% BTC', $r['2028-06']['btc_persen'], 60.0, $lulus, 0.001);
periksa('porsi alt selalu pelengkap', $r['2028-06']['alt_persen'], 40.0, $lulus, 0.001);

// Dibandingkan langsung dengan spreadsheet baris Desember 2028
periksa('2028-12 saldo akhir', $r['2028-12']['saldo_akhir'], 175786357.78, $lulus, 2.0);
periksa('2028-12 alokasi BTC', $r['2028-12']['alokasi_btc'], 105471815, $lulus, 2.0);
periksa('2028-12 alokasi Alt', $r['2028-12']['alokasi_alt'], 70314543, $lulus, 2.0);

// ---------------------------------------------------------------
echo "\n=== UJI 3: kolom aktual dari snapshot portofolio ===\n";
// ---------------------------------------------------------------
MonthlyPortfolioSnapshot::create(['year_month' => '2026-09', 'ending_valuation_idr' => 24821094, 'is_finalized' => false]);

$h2 = $svc->build();
$r2 = collect($h2['rows'])->keyBy('year_month');

periksa('aktual September terbaca', $r2['2026-09']['aktual'], 24821094, $lulus);
periksa('selisih terhadap rencana', $r2['2026-09']['selisih'], 665094, $lulus);
periksa('selisih dalam persen', $r2['2026-09']['selisih_persen'], 2.7533, $lulus, 0.01);
periksa('bulan mendatang tetap kosong', $r2['2026-11']['aktual'], null, $lulus);
periksa('ditandai sudah lewat', $r2['2026-09']['sudah_lewat'], true, $lulus);
periksa('bulan mendatang belum lewat', $r2['2026-11']['sudah_lewat'], false, $lulus);

// ---------------------------------------------------------------
echo "\n=== UJI 4: asumsi bisa diubah ===\n";
// ---------------------------------------------------------------
Setting::updateOrCreate(['key' => 'projection_default_return'], ['value' => '2.00']);
Setting::updateOrCreate(['key' => 'projection_months'], ['value' => '6']);
Setting::updateOrCreate(['key' => 'projection_monthly_topup'], ['value' => '1000000']);

$h3 = $svc->build();
periksa('durasi mengikuti pengaturan', count($h3['rows']), 6, $lulus);
periksa('return default berubah', $h3['rows'][1]['return_persen'], 2.00, $lulus, 0.001);
periksa('return khusus tetap dipakai', $h3['rows'][0]['return_persen'], 9.80, $lulus, 0.001);
periksa('top up mengikuti pengaturan', $h3['rows'][1]['top_up'], 1000000, $lulus);
// Okt: 24.156.000 + 1.000.000 + (24.156.000 x 2%) = 25.639.120
periksa('saldo ikut menyesuaikan', $h3['rows'][1]['saldo_akhir'], 25639120.0, $lulus);

// ---------------------------------------------------------------
echo "\n=== UJI 5: isian rusak tidak menggagalkan proyeksi ===\n";
// ---------------------------------------------------------------
Setting::updateOrCreate(['key' => 'projection_allocation_phases'], ['value' => "2026-09: 20\nbaris ngawur\n2026-11: xyz\n2026-12: 50"]);
Setting::updateOrCreate(['key' => 'projection_start_month'], ['value' => 'bukan-tanggal']);

$h4 = $svc->build();
periksa('tetap menghasilkan baris', count($h4['rows']) > 0, true, $lulus);
periksa('bulan mulai jatuh ke bawaan', $h4['rows'][0]['year_month'], '2026-09', $lulus);
periksa('fase yang sah tetap terbaca', $h4['rows'][0]['btc_persen'], 20.0, $lulus, 0.001);
periksa('baris rusak diabaikan', $h4['rows'][2]['btc_persen'], 20.0, $lulus, 0.001);

echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
echo "\n(Tidak ada query yang menyentuh database produksi.)\n";
