<?php
/**
 * Uji: di halaman Portofolio, aset bernilai di bawah $2 disembunyikan dari
 * tabel, tetapi tetap dihitung di total.
 *
 * SQLite di memori + Http::fake, jadi tidak menyentuh database atau bursa.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

Config::set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
Config::set('database.default', 'uji');
DB::purge('uji');
Config::set('cache.default', 'array');
// Saldo uji disuplai lewat API palsu, jadi mode API dinyalakan di sini
Config::set('services.tokocrypto.portfolio_from_api', true);

Schema::create('settings', function ($t) { $t->id(); $t->string('key'); $t->text('value')->nullable(); $t->timestamps(); });
Schema::create('trades', function ($t) {
    $t->id(); $t->string('symbol'); $t->string('type');
    $t->decimal('price', 24, 8); $t->decimal('amount', 24, 8); $t->decimal('total', 24, 8)->default(0);
    $t->dateTime('trade_time'); $t->timestamps();
});
Setting::create(['key' => 'tokocrypto_api_key', 'value' => 'kunci-uji']);
Setting::create(['key' => 'tokocrypto_api_secret', 'value' => 'rahasia-uji']);

$lulus = true;
function periksa(string $label, $dapat, $harus, bool &$lulus): void
{
    $ok = is_float($harus) ? (is_numeric($dapat) && abs((float)$dapat - $harus) < 1e-6) : $dapat === $harus;
    $lulus = $lulus && $ok;
    printf("  [%s] %-50s %18s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label,
        var_export($dapat, true), var_export($harus, true));
}

$saldo = [];
Http::fake(function ($request) use (&$saldo) {
    $url = $request->url();
    if (str_contains($url, 'ticker')) {
        return Http::response([
            ['symbol' => 'BTCUSDT', 'price' => '100000'], ['symbol' => 'XLMUSDT', 'price' => '0.15'],
            ['symbol' => 'TRXUSDT', 'price' => '0.25'], ['symbol' => 'USDTIDR', 'price' => '16000'],
        ]);
    }
    if (str_contains($url, '/open/v1/account/spot')) {
        return Http::response(['code' => 0, 'msg' => 'Success', 'data' => ['accountAssets' => $saldo]]);
    }
    return Http::response([], 404);
});

$user = new App\Models\User(['name' => 'Uji', 'email' => 'uji@x.test']); $user->id = 1;
$user->role = 'super_admin';
auth()->setUser($user);
app('request')->setLaravelSession(app('session.store'));

$ctl = app(App\Http\Controllers\PortfolioController::class);
$buka = function () use ($ctl) {
    Cache::flush();
    $v = $ctl->index(app(App\Services\CostBasisService::class));
    return [$v->getData(), $v->render()];
};
$nama = fn($d) => array_column($d['assets'], 'asset');

echo "=== UJI 1: aset < \$2 disembunyikan, total utuh ===\n";
$saldo = [
    ['asset' => 'BTC', 'free' => '0.01', 'locked' => '0'],        // $1000
    ['asset' => 'XLM', 'free' => '10', 'locked' => '0'],          // $1,50  -> sembunyi
    ['asset' => 'TRX', 'free' => '8', 'locked' => '0'],           // $2,00  -> tampil (tepat batas)
    ['asset' => 'USDT', 'free' => '0.4', 'locked' => '0'],        // $0,40  -> sembunyi
    ['asset' => 'ANEH', 'free' => '5', 'locked' => '0'],          // tanpa harga -> sembunyi, disebut namanya
];
[$d, $html] = $buka();
periksa('BTC tampil', in_array('BTC', $nama($d), true), true, $lulus);
periksa('TRX tepat $2 tampil', in_array('TRX', $nama($d), true), true, $lulus);
periksa('XLM $1,50 disembunyikan', in_array('XLM', $nama($d), true), false, $lulus);
periksa('USDT $0,40 disembunyikan', in_array('USDT', $nama($d), true), false, $lulus);
periksa('aset tanpa harga disembunyikan', in_array('ANEH', $nama($d), true), false, $lulus);
periksa('nama aset tanpa harga disebut', $d['aset_tanpa_harga'], ['ANEH'], $lulus);
periksa('catatan tanpa harga tampil', str_contains($html, 'Tanpa harga pasar, disembunyikan: ANEH'), true, $lulus);
periksa('jumlah disembunyikan', $d['aset_kecil_jumlah'], 2, $lulus);
periksa('nilai disembunyikan', (float)$d['aset_kecil_nilai_usdt'], 1.9, $lulus);
periksa('total tetap hitung semua (1000+1,5+2+0,4)', (float)$d['total_valuation_usdt'], 1003.9, $lulus);
periksa('catatan tampil di halaman', str_contains($html, '2 aset bernilai di bawah $2 disembunyikan'), true, $lulus);
periksa('baris XLM tidak dirender', str_contains($html, 'data-sort-asset="XLM"'), false, $lulus);

echo "\n=== UJI 2: tidak ada aset kecil -> tanpa catatan ===\n";
$saldo = [['asset' => 'BTC', 'free' => '0.01', 'locked' => '0']];
[$d, $html] = $buka();
periksa('jumlah disembunyikan 0', $d['aset_kecil_jumlah'], 0, $lulus);
periksa('catatan tidak tampil', str_contains($html, 'aset bernilai di bawah'), false, $lulus);

echo "\n=== UJI 3: semua aset kecil ===\n";
$saldo = [['asset' => 'XLM', 'free' => '10', 'locked' => '0']];
[$d, $html] = $buka();
periksa('tabel kosong', count($d['assets']), 0, $lulus);
periksa('pesan kosong menjelaskan alasannya', str_contains($html, 'Semua aset bernilai di bawah $2'), true, $lulus);

echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
