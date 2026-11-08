<?php
/**
 * Uji halaman Kalkulator DCA & TP (/portfolio/dca-calculator).
 *
 * Memeriksa aset yang ditampilkan dan posisinya. Perhitungan DCA/TP ada di
 * JavaScript halaman; dengan --pratinjau, halaman ditulis ke
 * public/_pratinjau_dca.html untuk diuji di browser lewat window.hitungDca.
 *
 * SQLite di memori + Http::fake: tidak menyentuh database asli maupun bursa.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Trade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

Config::set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
Config::set('database.default', 'uji');
DB::purge('uji');
Config::set('cache.default', 'array');

Schema::create('settings', function ($t) { $t->id(); $t->string('key'); $t->text('value')->nullable(); $t->timestamps(); });
Schema::create('trades', function ($t) {
    $t->id(); $t->string('symbol'); $t->string('type');
    $t->decimal('price', 16, 8); $t->decimal('amount', 16, 8); $t->decimal('total', 16, 8);
    $t->dateTime('trade_time'); $t->decimal('stoch_rsi_k', 5, 2)->nullable(); $t->decimal('stoch_rsi_d', 5, 2)->nullable();
    $t->string('divergence')->nullable(); $t->text('notes')->nullable(); $t->string('exchange_order_id')->nullable(); $t->timestamps();
});

Http::fake(fn($r) => str_contains($r->url(), 'ticker')
    ? Http::response([
        ['symbol' => 'STRKUSDT', 'price' => '0.04'], ['symbol' => 'BTCUSDT', 'price' => '100000'],
        ['symbol' => 'XLMUSDT', 'price' => '0.15'], ['symbol' => 'USDTIDR', 'price' => '16000'],
    ])
    : Http::response([], 404));

$t = fn($sym, $type, $price, $amount, $tgl) => Trade::create(['symbol' => $sym, 'type' => $type, 'price' => $price,
    'amount' => $amount, 'total' => $price * $amount, 'trade_time' => "2026-10-{$tgl} 10:00:00"]);
$t('USDT', 'DEPOSIT', 1, 500, '01');
$t('STRKUSDT', 'BUY', 0.05, 1000, '02');      // modal 50, avg 0.05, nilai 40
$t('BTCUSDT', 'BUY', 80000, 0.002, '03');     // modal 160, nilai 200
$t('XLMUSDT', 'BUY', 0.2, 10, '04');          // nilai 1,50 -> disembunyikan

$lulus = true;
function periksa(string $label, $dapat, $harus, bool &$lulus): void
{
    $ok = is_float($harus) ? (is_numeric($dapat) && abs((float)$dapat - $harus) < 1e-6) : $dapat === $harus;
    $lulus = $lulus && $ok;
    printf("  [%s] %-48s %18s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label,
        var_export($dapat, true), var_export($harus, true));
}

$user = new App\Models\User(['name' => 'Uji', 'email' => 'uji@x.test']); $user->id = 1;
$user->role = 'super_admin';
auth()->setUser($user);
app('request')->setLaravelSession(app('session.store'));

$v = app(App\Http\Controllers\PortfolioController::class)->dcaCalculator(app(App\Services\CostBasisService::class));
$d = $v->getData();
$aset = collect($d['assets'])->keyBy('asset');

echo "=== UJI 1: aset yang ditampilkan ===\n";
periksa('urutan: nilai terbesar dulu', array_column($d['assets'], 'asset'), ['BTC', 'STRK'], $lulus);
periksa('USDT (kas) tidak ditampilkan', $aset->has('USDT'), false, $lulus);
periksa('XLM < $2 tidak ditampilkan', $aset->has('XLM'), false, $lulus);

echo "\n=== UJI 2: posisi sama dengan halaman Portofolio ===\n";
periksa('STRK saldo', $aset['STRK']['amount'], 1000.0, $lulus);
periksa('STRK average', $aset['STRK']['avg_price'], 0.05, $lulus);
periksa('STRK modal', $aset['STRK']['cost'], 50.0, $lulus);
periksa('STRK harga pasar', $aset['STRK']['price'], 0.04, $lulus);
periksa('STRK PNL %', $aset['STRK']['pnl_percent'], -20.0, $lulus);
periksa('kurs', $d['usdt_idr_rate'], 16000.0, $lulus);
periksa('total nilai aset aktif', $d['total_value'], 240.0, $lulus);

echo "\n=== UJI 3: halaman & menu ===\n";
$html = $v->render();
periksa('halaman ter-render', str_contains($html, 'Kalkulator DCA &amp; Target TP'), true, $lulus);
periksa('kartu STRK ada', str_contains($html, 'data-aset="STRK"'), true, $lulus);
periksa('menu di sidebar', str_contains($html, route('portfolio.dca-calculator')), true, $lulus);
periksa('kunci menu terdaftar', in_array('dca_calculator', App\Support\Menu::keys(), true), true, $lulus);
$rute = app('router')->getRoutes()->getByName('portfolio.dca-calculator');
periksa('rute dijaga menu:dca_calculator', in_array('menu:dca_calculator', $rute->gatherMiddleware(), true), true, $lulus);

if (in_array('--pratinjau', $argv, true)) {
    file_put_contents(__DIR__ . '/../public/_pratinjau_dca.html',
        str_replace('http://localhost/css/', 'http://localhost/pelagic_dashboard/public/css/', $html));
    echo "\n(pratinjau ditulis ke public/_pratinjau_dca.html)\n";
}

echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
