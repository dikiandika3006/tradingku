<?php
/**
 * Uji koneksi Tokocrypto lewat Open API (/open/v1).
 *
 * - Saldo dibaca dari data.accountAssets (free + locked), debu dibuang.
 * - API gagal (kode -2015, HTTP 500, timeout) -> kembali ke saldo jurnal.
 * - Kas IDR dinilai pakai kurs; saldo IDR negatif tetap 0.
 * - Open order diterjemahkan ke format yang dipakai halaman Open Orders.
 * - Baris IDR di halaman Portofolio tidak punya PNL.
 *
 * SQLite di memori + Http::fake, jadi tidak menyentuh database atau bursa.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use App\Models\Trade;
use App\Services\CostBasisService;
use App\Services\TokocryptoService;
use Illuminate\Support\Facades\Cache;
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
    $t->decimal('price', 24, 8); $t->decimal('amount', 24, 8); $t->decimal('total', 24, 8)->default(0);
    $t->dateTime('trade_time'); $t->decimal('stoch_rsi_k', 8, 2)->nullable(); $t->decimal('stoch_rsi_d', 8, 2)->nullable();
    $t->string('divergence')->nullable(); $t->text('notes')->nullable(); $t->timestamps();
});
Setting::create(['key' => 'tokocrypto_api_key', 'value' => 'kunci-uji']);
Setting::create(['key' => 'tokocrypto_api_secret', 'value' => 'rahasia-uji']);

$lulus = true;
function periksa(string $label, $dapat, $harus, bool &$lulus): void
{
    $ok = is_float($harus) ? (is_numeric($dapat) && abs((float)$dapat - $harus) < 1e-6) : $dapat === $harus;
    $lulus = $lulus && $ok;
    printf("  [%s] %-52s %18s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label,
        var_export($dapat, true), var_export($harus, true));
}

// Perilaku bursa palsu, diubah per skenario
$mode = 'sukses';
$dipanggil = [];
Http::fake(function ($request) use (&$mode, &$dipanggil) {
    $url = $request->url();
    if (str_contains($url, '/api/v3/ticker/price') || str_contains($url, 'ticker')) {
        return Http::response([
            ['symbol' => 'BTCUSDT', 'price' => '100000'], ['symbol' => 'STRKUSDT', 'price' => '0.05'],
            ['symbol' => 'USDTIDR', 'price' => '16000'],
        ]);
    }
    if (str_contains($url, 'tokocrypto.com/open/v1/')) {
        $dipanggil[] = parse_url($url, PHP_URL_PATH);
        $dipanggil['header'] = $request->header('X-MBX-APIKEY')[0] ?? null;
        $dipanggil['ttd'] = str_contains($url, 'signature=') && str_contains($url, 'timestamp=');
        if ($mode === 'tolak') return Http::response(['code' => -2015, 'msg' => 'Invalid API-key, IP, or permissions for action.'], 401);
        if ($mode === 'kode') return Http::response(['code' => 3701, 'msg' => 'error', 'data' => null], 200);
        if ($mode === 'putus') throw new \Illuminate\Http\Client\ConnectionException('timeout');
        if (str_contains($url, '/account/spot')) {
            return Http::response(['code' => 0, 'msg' => 'Success', 'data' => ['accountAssets' => [
                ['asset' => 'BTC', 'free' => '0.01', 'locked' => '0.005'],
                ['asset' => 'STRK', 'free' => '1000', 'locked' => '0'],
                ['asset' => 'IDR', 'free' => '1600000', 'locked' => '0'],
                ['asset' => 'DEBU', 'free' => '0.000001', 'locked' => '0'],
                ['asset' => 'KOSONG', 'free' => '0', 'locked' => '0'],
            ]]]);
        }
        if (str_contains($url, '/orders')) {
            return Http::response(['code' => 0, 'msg' => 'Success', 'data' => ['list' => [
                ['orderId' => 1, 'symbol' => 'BTC_USDT', 'side' => 0, 'type' => 1, 'status' => 0,
                 'price' => '75000', 'origQty' => '0.00149', 'executedQty' => '0', 'stopPrice' => '0', 'time' => 1, 'createTime' => 1793929722701],
                ['orderId' => 2, 'symbol' => 'STRK_IDR', 'side' => 1, 'type' => 4, 'status' => 1,
                 'price' => '900', 'origQty' => '50', 'executedQty' => '10', 'stopPrice' => '850', 'createTime' => 1793000000000],
            ]]]);
        }
    }
    return Http::response([], 404);
});

// Jurnal: dipakai saat API gagal
Trade::create(['symbol' => 'STRKUSDT', 'type' => 'BUY', 'price' => 0.04, 'amount' => 500, 'total' => 20, 'trade_time' => '2026-10-01 10:00:00']);
Trade::create(['symbol' => 'USDT', 'type' => 'DEPOSIT', 'price' => 1, 'amount' => 100, 'total' => 0, 'trade_time' => '2026-09-30 10:00:00']);
Trade::create(['symbol' => 'IDR', 'type' => 'DEPOSIT', 'price' => 1, 'amount' => 1600000, 'total' => 0, 'trade_time' => '2026-09-29 10:00:00']);

$svc = app(TokocryptoService::class);
$cari = fn(array $p, string $a) => collect($p['assets'])->firstWhere('asset', $a);

echo "=== UJI 1: saldo dari Open API ===\n";
$p = $svc->getPortfolio();
periksa('is_live', $p['is_live'], true, $lulus);
periksa('endpoint resmi /open/v1/account/spot', in_array('/open/v1/account/spot', $dipanggil, true), true, $lulus);
periksa('header X-MBX-APIKEY terkirim', $dipanggil['header'], 'kunci-uji', $lulus);
periksa('ada timestamp & signature', $dipanggil['ttd'], true, $lulus);
periksa('BTC = free + locked', (float)$cari($p, 'BTC')['total'], 0.015, $lulus);
periksa('sumber = API', $cari($p, 'BTC')['source'], 'API', $lulus);
periksa('saldo STRK dari bursa, bukan jurnal (500)', (float)$cari($p, 'STRK')['total'], 1000.0, $lulus);
periksa('debu & saldo nol dibuang', $cari($p, 'DEBU') === null && $cari($p, 'KOSONG') === null, true, $lulus);

echo "\n=== UJI 2: kas IDR ikut dinilai ===\n";
periksa('harga IDR = 1/kurs', (float)$cari($p, 'IDR')['price'], 1 / 16000, $lulus);
periksa('nilai Rp1,6 jt = $100', (float)$cari($p, 'IDR')['value_usdt'], 100.0, $lulus);
periksa('total = 1500 + 50 + 100', (float)$p['total_usdt'], 1650.0, $lulus);

echo "\n=== UJI 3: cache 15 detik ===\n";
$sebelum = count(array_filter($dipanggil, fn($v, $k) => is_int($k) && $v === '/open/v1/account/spot', ARRAY_FILTER_USE_BOTH));
$svc->getPortfolio();
$sesudah = count(array_filter($dipanggil, fn($v, $k) => is_int($k) && $v === '/open/v1/account/spot', ARRAY_FILTER_USE_BOTH));
periksa('panggilan kedua tidak ke bursa lagi', $sesudah, $sebelum, $lulus);

foreach (['tolak' => 'key ditolak (-2015)', 'kode' => 'code bukan 0', 'putus' => 'koneksi putus'] as $m => $judul) {
    echo "\n=== UJI 4: {$judul} -> kembali ke jurnal ===\n";
    Cache::flush(); $mode = $m;
    $p = $svc->getPortfolio();
    periksa('is_live', $p['is_live'], false, $lulus);
    periksa('STRK dari jurnal', (float)($cari($p, 'STRK')['total'] ?? 0), 500.0, $lulus);
    periksa('open orders kosong, tidak error', $svc->getOpenOrders(), [], $lulus);
}

echo "\n=== UJI 5: saldo IDR negatif di jurnal tidak memotong total ===\n";
Cache::flush(); $mode = 'tolak';
Trade::create(['symbol' => 'BTCIDR', 'type' => 'BUY', 'price' => 1600000000, 'amount' => 0.002, 'total' => 3200000, 'trade_time' => '2026-10-02 10:00:00']);
$p = $svc->getPortfolio();
periksa('IDR jurnal = -1,6 jt', (float)$cari($p, 'IDR')['total'], -1600000.0, $lulus);
periksa('nilai IDR negatif = 0', (float)$cari($p, 'IDR')['value_usdt'], 0.0, $lulus);

echo "\n=== UJI 6: open orders diterjemahkan ===\n";
Cache::flush(); $mode = 'sukses';
$o = $svc->getOpenOrders();
periksa('jumlah', count($o), 2, $lulus);
periksa('BTC_USDT -> BTCUSDT', $o[0]['symbol'], 'BTCUSDT', $lulus);
periksa('side 0 -> BUY', $o[0]['side'], 'BUY', $lulus);
periksa('type 1 -> LIMIT', $o[0]['type'], 'LIMIT', $lulus);
periksa('status 0 -> NEW', $o[0]['status'], 'NEW', $lulus);
periksa('time = createTime (ms)', $o[0]['time'], 1793929722701, $lulus);
periksa('side 1 -> SELL', $o[1]['side'], 'SELL', $lulus);
periksa('type 4 -> STOP_LOSS_LIMIT', $o[1]['type'], 'STOP_LOSS_LIMIT', $lulus);
periksa('status 1 -> PARTIALLY_FILLED', $o[1]['status'], 'PARTIALLY_FILLED', $lulus);
periksa('STRK_IDR -> STRKIDR', $o[1]['symbol'], 'STRKIDR', $lulus);
periksa('minta order terbuka (type=1)', (bool)array_filter($dipanggil, fn($v) => $v === '/open/v1/orders'), true, $lulus);

echo "\n=== UJI 7: baris IDR di halaman Portofolio tanpa PNL ===\n";
Cache::flush(); $mode = 'sukses';
$ctl = app(App\Http\Controllers\PortfolioController::class);
$m = new ReflectionMethod($ctl, 'holdings');
$h = $m->invoke($ctl, app(CostBasisService::class), $svc->getPortfolio(), $svc->getAllPrices());
$idr = collect($h['assets'])->firstWhere('asset', 'IDR');
$kunciPnl = collect(array_keys($idr ?? []))->first(fn($k) => str_contains($k, 'pnl') && str_contains($k, 'percent'))
    ?? collect(array_keys($idr ?? []))->first(fn($k) => str_contains(strtolower($k), 'pnl'));
periksa('baris IDR ada', $idr !== null, true, $lulus);
periksa("PNL IDR = 0 ({$kunciPnl})", (float)($idr[$kunciPnl] ?? -1), 0.0, $lulus);
periksa('nilai IDR $100 masuk total', (float)$h['total_value_usdt'], 1650.0, $lulus);

echo "\n=== UJI 8: halaman Antrean Pesanan ===\n";
$user = new App\Models\User(['name' => 'Uji', 'email' => 'uji@x.test']); $user->id = 1;
$user->role = 'super_admin'; // menu tampil semua tanpa tabel roles
auth()->setUser($user);
app('request')->setLaravelSession(app('session.store'));
$render = function () use ($ctl) {
    $v = $ctl->openOrders(Illuminate\Http\Request::create('/portfolio/open-orders'));
    return [$v->getData(), $v->render()];
};

Cache::flush(); $mode = 'sukses';
[$d, $html] = $render();
periksa('api_gagal = false', $d['api_gagal'], false, $lulus);
periksa('2 order dikirim ke view', count($d['open_orders']), 2, $lulus);
$strk = collect($d['open_orders'])->firstWhere('symbol', 'STRKIDR');
// 900 IDR x 50 = Rp45.000 / 16.000 = $2,8125
periksa('total order IDR dikonversi ke USDT', (float)$strk['total_usdt'], 2.8125, $lulus);
$btc = collect($d['open_orders'])->firstWhere('symbol', 'BTCUSDT');
periksa('total order USDT tetap', (float)$btc['total_usdt'], 75000 * 0.00149, $lulus);
periksa('order terbaru di atas', $d['open_orders'][0]['symbol'], 'BTCUSDT', $lulus);
periksa('badge "Tersambung" tampil', str_contains($html, 'Tersambung Ke API Live'), true, $lulus);
periksa('badge "Gagal" tidak tampil', str_contains($html, 'Gagal Terhubung Ke API'), false, $lulus);
periksa('BTCUSDT tampil di tabel', str_contains($html, 'BTCUSDT'), true, $lulus);
periksa('STOP_LOSS_LIMIT tampil di tabel', str_contains($html, 'STOP_LOSS_LIMIT'), true, $lulus);

Cache::flush(); $mode = 'tolak';
[$d, $html] = $render();
periksa('API ditolak -> api_gagal = true', $d['api_gagal'], true, $lulus);
periksa('badge "Gagal" tampil', str_contains($html, 'Gagal Terhubung Ke API'), true, $lulus);
periksa('badge "Tersambung" tidak tampil', str_contains($html, 'Tersambung Ke API Live'), false, $lulus);
periksa('tidak mengaku "tidak ada order"', str_contains($html, 'Tidak ada antrean pesanan'), false, $lulus);

echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
