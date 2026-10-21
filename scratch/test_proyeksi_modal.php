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

use App\Models\CapitalProjectionOverride;
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
Schema::create('capital_projection_overrides', function ($t) {
    $t->id(); $t->string('year_month')->unique();
    $t->decimal('top_up', 20, 2)->nullable(); $t->decimal('return_idr', 20, 2)->nullable();
    $t->decimal('alokasi_btc', 20, 2)->nullable(); $t->decimal('alokasi_alt', 20, 2)->nullable();
    $t->timestamps();
});
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

// Portofolio palsu: dimatikan dulu supaya baris bulan berjalan memakai rencana,
// lalu dinyalakan di UJI 9 untuk memeriksa pengambilan dari portofolio nyata.
class FakeTokoProyeksi extends App\Services\TokocryptoService
{
    public static bool $aktif = false;
    public function __construct() {}
    public function getPortfolio(): array
    {
        if (!self::$aktif) {
            return ['usdt_idr_rate' => 17000.0, 'assets' => []];
        }
        $a = fn($n, $v) => ['asset' => $n, 'total' => 1, 'price' => 1, 'value_usdt' => $v];
        return ['usdt_idr_rate' => 17000.0, 'assets' => [
            $a('BTC', 400.0),       // 6.800.000
            $a('LUMIA', 380.0),     // 6.460.000 \
            $a('ASTER', 120.0),     // 2.040.000  > altcoin 9.350.000
            $a('STRK', 50.0),       //   850.000 /
            $a('USDT', 600.0),      // 10.200.000  kas
            $a('IDR', 0.0),         // fiat bernilai nol
        ]];
    }
}
app()->instance(App\Services\TokocryptoService::class, new FakeTokoProyeksi());

// Tanggal dipatok supaya "bulan berjalan" selalu Oktober 2026
\Carbon\Carbon::setTestNow('2026-10-21 10:00:00');

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
// Selisih = Saldo Akhir rencana - Aktual: 24.156.000 - 24.821.094
periksa('selisih = rencana - aktual', $r2['2026-09']['selisih'], -665094, $lulus);
periksa('selisih dalam persen', $r2['2026-09']['selisih_persen'], -2.7533, $lulus, 0.01);
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

// ---------------------------------------------------------------
echo "\n=== UJI 6: nilai nominal ditimpa per baris ===\n";
// ---------------------------------------------------------------
Setting::whereIn('key', array_keys(CapitalProjectionService::DEFAULTS))->delete();   // kembali ke bawaan
CapitalProjectionOverride::create(['year_month' => '2026-10', 'top_up' => 5000000, 'return_idr' => 1000000]);

$h5 = app(CapitalProjectionService::class)->build();
$r5 = collect($h5['rows'])->keyBy('year_month');

periksa('top up mengikuti isian', $r5['2026-10']['top_up'], 5000000, $lulus);
periksa('return Rp mengikuti isian', $r5['2026-10']['return_idr'], 1000000, $lulus);
// Persen diturunkan: 1.000.000 / 24.156.000 = 4,1397%
periksa('persen diturunkan dari nominal', $r5['2026-10']['return_persen'], 4.1397, $lulus, 0.001);
periksa('saldo akhir ikut berubah', $r5['2026-10']['saldo_akhir'], 30156000, $lulus);
periksa('bulan sesudahnya ikut bergeser', $r5['2026-11']['modal_awal'], 30156000, $lulus);
periksa('ditandai sebagai timpaan', $r5['2026-10']['ditimpa']['top_up'], true, $lulus);
periksa('kolom lain tidak ditandai', $r5['2026-10']['ditimpa']['alokasi_btc'], false, $lulus);

// ---------------------------------------------------------------
echo "\n=== UJI 7: alokasi nominal ditimpa ===\n";
// ---------------------------------------------------------------
CapitalProjectionOverride::updateOrCreate(['year_month' => '2026-09'], ['alokasi_btc' => 10000000]);

$h6 = app(CapitalProjectionService::class)->build();
$r6 = collect($h6['rows'])->keyBy('year_month');

periksa('alokasi BTC mengikuti isian', $r6['2026-09']['alokasi_btc'], 10000000, $lulus);
// Alt otomatis mengisi sisa: 24.156.000 - 10.000.000
periksa('alokasi Alt mengisi sisa saldo', $r6['2026-09']['alokasi_alt'], 14156000, $lulus);
periksa('persen BTC diturunkan', $r6['2026-09']['btc_persen'], 41.3976, $lulus, 0.001);
periksa('jumlahnya tetap pas', $r6['2026-09']['alokasi_timpang'], false, $lulus);

// Menimpa keduanya sampai tidak menjumlah -> harus ditandai
CapitalProjectionOverride::updateOrCreate(['year_month' => '2026-09'],
    ['alokasi_btc' => 10000000, 'alokasi_alt' => 1000000]);

periksa('alokasi timpang terdeteksi',
    collect(app(CapitalProjectionService::class)->build()['rows'])
        ->keyBy('year_month')['2026-09']['alokasi_timpang'], true, $lulus);

// ---------------------------------------------------------------
echo "\n=== UJI 8: nol yang disengaja beda dari kosong ===\n";
// ---------------------------------------------------------------
CapitalProjectionOverride::query()->delete();
CapitalProjectionOverride::create(['year_month' => '2026-11', 'top_up' => 0]);

$h8 = app(CapitalProjectionService::class)->build();
$r8 = collect($h8['rows'])->keyBy('year_month');

periksa('top up nol dihormati', $r8['2026-11']['top_up'], 0.0, $lulus);
periksa('ditandai ditimpa meski nol', $r8['2026-11']['ditimpa']['top_up'], true, $lulus);
periksa('bulan lain tetap otomatis', $r8['2026-12']['top_up'], 3000000, $lulus);

// ---------------------------------------------------------------
echo "\n=== UJI 9: bulan berjalan dari portofolio saat ini ===\n";
// ---------------------------------------------------------------
CapitalProjectionOverride::query()->delete();
MonthlyPortfolioSnapshot::query()->delete();
MonthlyPortfolioSnapshot::create(['year_month' => '2026-09', 'ending_valuation_idr' => 24821094, 'is_finalized' => true]);
// Snapshot harian Oktober sengaja dibuat berbeda: yang live harus menang
MonthlyPortfolioSnapshot::create(['year_month' => '2026-10', 'ending_valuation_idr' => 11111111, 'is_finalized' => false]);
FakeTokoProyeksi::$aktif = true;

$h9 = app(CapitalProjectionService::class)->build();
$r9 = collect($h9['rows'])->keyBy('year_month');
$okt = $r9['2026-10'];

// Total = (400 + 380 + 120 + 50 + 600 + 0) x 17.000 = 26.350.000
periksa('aktual = saldo portofolio saat ini', $okt['aktual'], 26350000, $lulus);
periksa('bukan snapshot harian', $okt['aktual'] !== 11111111.0, true, $lulus);
periksa('alokasi BTC dari portofolio', $okt['alokasi_btc'], 6800000, $lulus);
periksa('alokasi Alt = semua non-BTC non-kas', $okt['alokasi_alt'], 9350000, $lulus);
periksa('kas USDT dipisahkan', $okt['kas_idr'], 10200000, $lulus);
periksa('BTC + Alt + Kas = Aktual',
    $okt['alokasi_btc'] + $okt['alokasi_alt'] + $okt['kas_idr'], 26350000, $lulus);
periksa('persen BTC relatif ke aktual', $okt['btc_persen'], 25.806, $lulus, 0.01);
periksa('ditandai dari portofolio', $okt['alokasi_dari_portofolio'], true, $lulus);
periksa('tidak ditandai timpang', $okt['alokasi_timpang'], false, $lulus);

// Selisih = Saldo Akhir Oktober (27.880.680) - Aktual (26.350.000)
periksa('selisih = saldo akhir - aktual', $okt['selisih'], 1530680, $lulus);

// Bulan lampau tetap dari snapshot dan alokasinya tetap rencana
periksa('September tetap dari snapshot', $r9['2026-09']['aktual'], 24821094, $lulus);
periksa('September alokasi tetap rencana', $r9['2026-09']['alokasi_dari_portofolio'], false, $lulus);
periksa('November belum punya aktual', $r9['2026-11']['aktual'], null, $lulus);

// Rantai rencana tidak boleh terpengaruh alokasi nyata
periksa('modal November tetap dari rencana', $r9['2026-11']['modal_awal'], 27880680, $lulus);

// Kartu ringkasan menunjuk bulan berjalan
periksa('posisi terkini = Oktober', $h9['summary']['posisi_terkini']['year_month'], '2026-10', $lulus);

// ---------------------------------------------------------------
echo "\n=== UJI 10: portofolio gagal diambil -> jatuh ke snapshot ===\n";
// ---------------------------------------------------------------
FakeTokoProyeksi::$aktif = false;   // aset kosong
$r10 = collect(app(CapitalProjectionService::class)->build()['rows'])->keyBy('year_month');

periksa('aktual Oktober dari snapshot harian', $r10['2026-10']['aktual'], 11111111, $lulus);
periksa('alokasi kembali ke rencana', $r10['2026-10']['alokasi_dari_portofolio'], false, $lulus);
periksa('alokasi rencana Oktober', $r10['2026-10']['alokasi_btc'], 8364204, $lulus);

\Carbon\Carbon::setTestNow();

echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
echo "\n(Tidak ada query yang menyentuh database produksi.)\n";
