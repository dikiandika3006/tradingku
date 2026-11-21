<?php
/**
 * Uji auto-post pembelian Tokocrypto ke jurnal (tokocrypto:sync-buys).
 *
 * SQLite di memori + Http::fake: tidak menyentuh database asli maupun bursa.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Console\Commands\SyncTokocryptoBuys;
use App\Models\Setting;
use App\Models\Trade;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
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
    $t->string('divergence')->nullable(); $t->text('notes')->nullable(); $t->timestamps();
});
$migrasi = require __DIR__ . '/../database/migrations/2026_11_06_000000_add_exchange_order_id_to_trades_table.php';
$migrasi->up();
$migrasi->up(); // aman dijalankan dua kali

Setting::create(['key' => 'tokocrypto_api_key', 'value' => 'kunci-uji']);
Setting::create(['key' => 'tokocrypto_api_secret', 'value' => 'rahasia-uji']);

$lulus = true;
function periksa(string $label, $dapat, $harus, bool &$lulus): void
{
    $ok = is_float($harus) ? (is_numeric($dapat) && abs((float)$dapat - $harus) < 1e-6) : $dapat === $harus;
    $lulus = $lulus && $ok;
    printf("  [%s] %-56s %20s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label,
        var_export($dapat, true), var_export($harus, true));
}

$ms = fn(string $wib) => Carbon::parse($wib, 'Asia/Jakarta')->getTimestampMs();
$fill = fn($order, $sym, $qty, $quote, $fee, $feeAset, $wib, $buyer = 1) => [
    'orderId' => $order, 'symbol' => $sym, 'price' => (string)($quote / $qty), 'qty' => (string)$qty,
    'quoteQty' => (string)$quote, 'commission' => (string)$fee, 'commissionAsset' => $feeAset,
    'isBuyer' => $buyer, 'time' => (string)$ms($wib),
];

$fills = [
    // XPL: satu order terisi dua kali, fee dalam XPL
    $fill('111', 'XPL_USDT', 200, 12, 0.3, 'XPL', '2026-11-12 09:00:00'),
    $fill('111', 'XPL_USDT', 250, 15, 0.375, 'XPL', '2026-11-12 09:05:00'),
    // jual, fee dalam USDT -> SELL, hasil jual dikurangi fee
    $fill('222', 'POL_USDT', 1000, 98, 0.098, 'USDT', '2026-11-12 10:00:00', 0),
    // USDT dibeli pakai rupiah, fee USDT -> DEPOSIT USDTIDR
    $fill('333', 'USDT_IDR', 55.18, 999971.96, 0.05518, 'USDT', '2026-11-12 11:00:00'),
    // BTC, fee dalam BNB (tidak mengubah jumlah / modal)
    $fill('444', 'BTC_USDT', 0.001, 75, 0.0001, 'BNB', '2026-11-12 12:00:00'),
    // ONDO: fee dalam quote USDT -> menambah modal
    $fill('555', 'ONDO_USDT', 100, 35, 0.035, 'USDT', '2026-11-12 13:00:00'),
    // order masih terbuka (terisi sebagian) -> ditunda
    $fill('666', 'SUI_USDT', 10, 20, 0.01, 'SUI', '2026-11-12 08:00:00'),
];
$terbuka = [['orderId' => '666', 'symbol' => 'SUI_USDT', 'side' => 0, 'type' => 1, 'status' => 1,
    'price' => '2', 'origQty' => '50', 'executedQty' => '10', 'createTime' => $ms('2026-11-12 07:00:00')]];

$mode = 'sukses';
$permintaan = [];
Http::fake(function ($request) use (&$mode, &$permintaan, &$fills, &$terbuka) {
    $url = $request->url();
    if (str_contains($url, '/open/v1/orders/trades')) {
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $permintaan[] = $q;
        if ($mode === 'gagal') return Http::response(['code' => -1001, 'msg' => 'gangguan'], 500);
        $dalam = array_values(array_filter($fills, fn($f) => (int)$f['time'] >= (int)$q['startTime'] && (int)$f['time'] <= (int)$q['endTime']));
        return Http::response(['code' => 0, 'msg' => 'Success', 'data' => ['list' => $dalam]]);
    }
    if (str_contains($url, '/open/v1/orders')) {
        return Http::response(['code' => 0, 'msg' => 'Success', 'data' => ['list' => $terbuka]]);
    }
    return Http::response([], 404); // harga & indikator: tidak tersedia di uji
});

$jalankan = function (array $opsi = []) {
    $kode = Artisan::call('tokocrypto:sync-buys', $opsi);
    return [$kode, Artisan::output()];
};
$catatan = fn($order) => Trade::where('exchange_order_id', $order)->first();

// Catatan manual ONDO yang sudah Anda isi sendiri (harga order, jumlah kotor)
$manualOndo = Trade::create(['symbol' => 'ONDOUSDT', 'type' => 'BUY', 'price' => 0.35, 'amount' => 100,
    'total' => 35, 'trade_time' => '2026-11-12 12:40:00', 'notes' => 'manual']);
// Catatan lama yang mirip tapi sudah lewat jendela waktu -> tidak boleh dicocokkan
Trade::create(['symbol' => 'BTCUSDT', 'type' => 'BUY', 'price' => 75000, 'amount' => 0.001,
    'total' => 75, 'trade_time' => '2026-11-09 12:00:00', 'notes' => 'lama']);

echo "=== UJI 1: dry-run tidak menulis apa pun ===\n";
Carbon::setTestNow(Carbon::parse('2026-11-12 14:00:00', 'Asia/Jakarta'));
[$kode, $out] = $jalankan(['--dry-run' => true]);
periksa('kode keluar sukses', $kode, 0, $lulus);
periksa('jumlah baris jurnal tetap 2', Trade::count(), 2, $lulus);
periksa('waktu sinkron tidak disimpan', Setting::where('key', SyncTokocryptoBuys::SETTING_TERAKHIR)->exists(), false, $lulus);
periksa('ringkasan menyebut "akan dicatat"', str_contains($out, 'akan dicatat'), true, $lulus);

echo "\n=== UJI 2: auto-post ===\n";
$permintaan = [];
[$kode, $out] = $jalankan();
periksa('kode keluar sukses', $kode, 0, $lulus);
periksa('startTime >= 12 Nov 00:00 WIB', (int)$permintaan[0]['startTime'], $ms(SyncTokocryptoBuys::MULAI), $lulus);
echo $out;

$xpl = $catatan('111');
periksa('XPL: satu baris dari dua fill', Trade::where('symbol', 'XPLUSDT')->count(), 1, $lulus);
periksa('XPL: BUY', $xpl->type, 'BUY', $lulus);
periksa('XPL: jumlah bersih 450 - 0,675 fee', (float)$xpl->amount, 449.325, $lulus);
periksa('XPL: total = 27 USDT', (float)$xpl->total, 27.0, $lulus);
periksa('XPL: harga = 27 / 449,325', (float)$xpl->price, round(27 / 449.325, 8), $lulus);
periksa('XPL: jam fill terakhir, WIB', $xpl->getRawOriginal('trade_time'), '2026-11-12 09:05:00', $lulus);
periksa('XPL: catatan menyebut order & fee', str_contains($xpl->notes, '#111') && str_contains($xpl->notes, '0.675 XPL'), true, $lulus);

$pol = $catatan('222');
periksa('POL: jual dicatat SELL', $pol?->type, 'SELL', $lulus);
periksa('POL: jumlah 1000', (float)($pol->amount ?? 0), 1000.0, $lulus);
periksa('POL: total 98 - 0,098 fee', (float)($pol->total ?? 0), 97.902, $lulus);

$usdt = $catatan('333');
periksa('USDT_IDR -> DEPOSIT', $usdt->type, 'DEPOSIT', $lulus);
periksa('USDT_IDR -> simbol USDTIDR', $usdt->symbol, 'USDTIDR', $lulus);
periksa('USDT bersih 55,18 - 0,05518', (float)$usdt->amount, 55.12482, $lulus);
periksa('total dalam rupiah', (float)$usdt->total, 999971.96, $lulus);

$btc = $catatan('444');
periksa('BTC: fee BNB tidak mengurangi jumlah', (float)$btc->amount, 0.001, $lulus);
periksa('BTC: modal tetap 75', (float)$btc->total, 75.0, $lulus);
periksa('BTC: catatan lama 9 Nov tidak dicocokkan', Trade::where('notes', 'lama')->first()->exchange_order_id, null, $lulus);

periksa('ONDO: catatan manual ditandai, bukan duplikat', $manualOndo->fresh()->exchange_order_id, '555', $lulus);
periksa('ONDO: tetap satu baris', Trade::where('symbol', 'ONDOUSDT')->count(), 1, $lulus);
periksa('ONDO: isi manual tidak diubah', (float)$manualOndo->fresh()->price, 0.35, $lulus);

periksa('SUI terbuka: ditunda', $catatan('666'), null, $lulus);
periksa('sinkron berikutnya mulai dari fill SUI', (int)Setting::where('key', SyncTokocryptoBuys::SETTING_TERAKHIR)->value('value'), $ms('2026-11-12 08:00:00'), $lulus);

echo "\n=== UJI 3: saldo jurnal = saldo yang benar-benar diterima ===\n";
$saldo = app(App\Services\TokocryptoService::class)->holdingsFromJournal();
periksa('XPL di jurnal', round($saldo['XPL'], 8), 449.325, $lulus);
periksa('USDT naik dari DEPOSIT, IDR tidak disentuh', isset($saldo['IDR']), false, $lulus);

echo "\n=== UJI 4: jalan ulang tidak menggandakan ===\n";
$sebelum = Trade::count();
[$kode, $out] = $jalankan();
periksa('jumlah baris tetap', Trade::count(), $sebelum, $lulus);
periksa('ringkasan "sudah ada di jurnal"', str_contains($out, 'sudah ada di jurnal'), true, $lulus);

echo "\n=== UJI 5: order SUI selesai -> dicatat ===\n";
$terbuka = [];
$fills[] = $fill('666', 'SUI_USDT', 40, 80, 0.04, 'SUI', '2026-11-12 14:30:00');
Carbon::setTestNow(Carbon::parse('2026-11-12 14:35:00', 'Asia/Jakarta'));
$jalankan();
$sui = $catatan('666');
periksa('SUI dicatat', $sui !== null, true, $lulus);
periksa('SUI: dua fill jadi satu (50 - 0,05 fee)', (float)($sui->amount ?? 0), 49.95, $lulus);
periksa('SUI: total 100 USDT', (float)($sui->total ?? 0), 100.0, $lulus);

echo "\n=== UJI 6: API gagal -> tidak menulis, tidak memajukan waktu ===\n";
$waktuSebelum = Setting::where('key', SyncTokocryptoBuys::SETTING_TERAKHIR)->value('value');
$sebelum = Trade::count();
$mode = 'gagal';
$fills[] = $fill('777', 'TIA_USDT', 5, 10, 0, 'TIA', '2026-11-12 14:40:00');
[$kode] = $jalankan();
periksa('kode keluar gagal', $kode, 1, $lulus);
periksa('jurnal tidak berubah', Trade::count(), $sebelum, $lulus);
periksa('waktu sinkron tidak maju', Setting::where('key', SyncTokocryptoBuys::SETTING_TERAKHIR)->value('value'), $waktuSebelum, $lulus);
$mode = 'sukses';
Carbon::setTestNow(Carbon::parse('2026-11-12 14:45:00', 'Asia/Jakarta'));
$jalankan();
periksa('setelah pulih, TIA tercatat', $catatan('777') !== null, true, $lulus);

echo "\n=== UJI 7: rentang > 24 jam dipecah per hari ===\n";
$permintaan = [];
Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00', 'Asia/Jakarta'));
$jalankan(['--since' => '2026-11-12 00:00']);
periksa('3 permintaan untuk ~2,5 hari', count($permintaan), 3, $lulus);
$rentangMaks = max(array_map(fn($q) => (int)$q['endTime'] - (int)$q['startTime'], $permintaan));
periksa('tiap jendela < 24 jam', $rentangMaks < 24 * 3600 * 1000, true, $lulus);
periksa('--since sebelum 12 Nov tetap dibatasi 12 Nov', (function () use ($jalankan, &$permintaan, $ms) {
    $permintaan = []; $jalankan(['--since' => '2026-10-01 00:00', '--dry-run' => true]);
    return (int)$permintaan[0]['startTime'];
})(), $ms(SyncTokocryptoBuys::MULAI), $lulus);
periksa('--backfill mengizinkan sebelum 12 Nov', (function () use ($jalankan, &$permintaan, $ms) {
    $permintaan = []; $jalankan(['--since' => '2026-11-10 00:00', '--backfill' => true, '--dry-run' => true]);
    return (int)$permintaan[0]['startTime'];
})(), $ms('2026-11-10 00:00'), $lulus);

echo "\n=== UJI 8: tanpa API key -> dilewati dengan tenang ===\n";
Setting::query()->delete();
Config::set('services.tokocrypto.api_key', '');
putenv('TOKOCRYPTO_API_KEY='); $_ENV['TOKOCRYPTO_API_KEY'] = ''; $_SERVER['TOKOCRYPTO_API_KEY'] = '';
[$kode, $out] = $jalankan();
periksa('kode keluar sukses', $kode, 0, $lulus);
periksa('pesan "belum terpasang"', str_contains($out, 'belum terpasang'), true, $lulus);

echo "\n=== UJI 9: jadwal ===\n";
$jadwal = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
    ->first(fn($e) => str_contains($e->command ?? '', 'tokocrypto:sync-buys'));
periksa('terdaftar', $jadwal !== null, true, $lulus);
periksa('tiap 5 menit', $jadwal?->expression, '*/5 * * * *', $lulus);

Carbon::setTestNow();
echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
