<?php
/**
 * Uji tombol "All" di form SELL (/trade).
 *
 * Memeriksa endpoint /api/trade-holding/{symbol}: saldo sama dengan rumus
 * portofolio, simbol pasangan maupun aset tunggal dikenali, transaksi yang
 * sedang diedit dikecualikan, dan saldo kosong/negatif tidak diisi.
 *
 * Memakai SQLite di memori, jadi tidak menyentuh database mana pun.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\TradeController;
use App\Models\Trade;
use App\Services\TokocryptoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Config::set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
Config::set('database.default', 'uji');
DB::purge('uji');

Schema::create('trades', function ($t) {
    $t->id();
    $t->string('symbol'); $t->string('type');
    $t->decimal('price', 24, 8); $t->decimal('amount', 24, 8); $t->decimal('total', 24, 8)->default(0);
    $t->dateTime('trade_time');
    $t->decimal('stoch_rsi_k', 8, 2)->nullable(); $t->decimal('stoch_rsi_d', 8, 2)->nullable();
    $t->string('divergence')->nullable(); $t->text('notes')->nullable();
    $t->timestamps();
});

$lulus = true;
function periksa(string $label, $dapat, $harus, bool &$lulus): void
{
    $ok = is_float($harus) ? (is_numeric($dapat) && abs((float)$dapat - $harus) < 1e-9) : $dapat === $harus;
    $lulus = $lulus && $ok;
    printf("  [%s] %-50s %16s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label,
        var_export($dapat, true), var_export($harus, true));
}

$t = fn($sym, $type, $price, $amount, $hari) => Trade::create([
    'symbol' => $sym, 'type' => $type, 'price' => $price, 'amount' => $amount,
    'total' => $price * $amount, 'trade_time' => "2026-10-{$hari} 10:00:00",
]);

$t('USDTIDR', 'DEPOSIT', 1, 1000, '01');
$t('STRKUSDT', 'BUY', 0.04, 5000, '02');
$t('STRKUSDT', 'BUY', 0.05, 1234.56789012, '03');
$jualSebagian = $t('STRKUSDT', 'SELL', 0.06, 1000, '04');
$t('HBARUSDT', 'BUY', 0.09, 300, '05');
$t('HBARUSDT', 'SELL', 0.10, 300, '06');                 // habis
$t('ASTERUSDT', 'BUY', 1.2, 0.00000001, '07');          // debu di bawah ambang

$ctl = app(TradeController::class);
$minta = function (string $sym, array $q = []) use ($ctl) {
    return $ctl->getHolding(Request::create("/api/trade-holding/{$sym}", 'GET', $q), $sym)->getData(true);
};

echo "=== UJI 1: saldo penuh dari jurnal ===\n";
$r = $minta('STRKUSDT');
periksa('aset dasar dikenali dari pasangan', $r['asset'], 'STRK', $lulus);
periksa('jumlah = beli - jual', (float)$r['amount'], 5234.56789012, $lulus);
periksa('teks tanpa nol berlebih', $r['amount_text'], '5234.56789012', $lulus);

echo "\n=== UJI 2: simbol tanpa pasangan & huruf kecil ===\n";
periksa('"strk" juga dikenali', (float)$minta('strk')['amount'], 5234.56789012, $lulus);

echo "\n=== UJI 3: sama dengan saldo di portofolio ===\n";
$h = app(TokocryptoService::class)->holdingsFromJournal();
periksa('STRK di holdingsFromJournal', (float)$h['STRK'], 5234.56789012, $lulus);
// USDT: setor 1000 - 200 - 61.72839 + 60 - 27 + 30 - 0.000000012
periksa('USDT ikut terhitung dari sisi quote', round((float)$minta('USDT')['amount'], 6),
    round(1000 - 200 - 61.7283945060 + 60 - 27 + 30 - 0.000000012, 6), $lulus);

echo "\n=== UJI 4: saldo habis / debu / tidak pernah dimiliki ===\n";
periksa('HBAR sudah dijual habis -> 0', (float)$minta('HBARUSDT')['amount'], 0.0, $lulus);
periksa('ASTER debu dianggap 0', (float)$minta('ASTERUSDT')['amount'], 0.0, $lulus);
periksa('aset asing -> 0', (float)$minta('XYZUSDT')['amount'], 0.0, $lulus);
periksa('teks saldo nol', $minta('XYZUSDT')['amount_text'], '0', $lulus);

echo "\n=== UJI 5: mode edit mengecualikan transaksi yang diedit ===\n";
$r = $minta('STRKUSDT', ['kecuali' => $jualSebagian->id]);
periksa('jual 1000 yang diedit tidak ikut mengurangi', (float)$r['amount'], 6234.56789012, $lulus);

echo "\n=== UJI 5b: teks dipotong, tidak dibulatkan ke atas ===\n";
Trade::create(['symbol' => 'PEPEUSDT', 'type' => 'DEPOSIT', 'price' => 1, 'amount' => 1.123456789,
    'total' => 0, 'trade_time' => '2026-10-08 10:00:00']);
periksa('1.123456789 -> 1.12345678 (bukan ...79)', $minta('PEPEUSDT')['amount_text'], '1.12345678', $lulus);
Trade::create(['symbol' => 'ONEUSDT', 'type' => 'DEPOSIT', 'price' => 1, 'amount' => 25,
    'total' => 0, 'trade_time' => '2026-10-08 10:00:00']);
periksa('bilangan bulat tanpa titik', $minta('ONEUSDT')['amount_text'], '25', $lulus);

echo "\n=== UJI 6: rute terdaftar dan butuh login ===\n";
$rute = app('router')->getRoutes()->getByName('api.trade-holding');
periksa('nama rute ada', $rute !== null, true, $lulus);
periksa('URI', $rute?->uri(), 'api/trade-holding/{symbol}', $lulus);
periksa('di belakang middleware auth', in_array('auth', $rute?->gatherMiddleware() ?? []), true, $lulus);

echo "\n=== UJI 7: view terkompilasi & tombol ada ===\n";
$html = file_get_contents(__DIR__ . '/../resources/views/trade/index.blade.php');
periksa('tombol #btnAllToken ada', str_contains($html, 'id="btnAllToken"'), true, $lulus);
periksa('default tersembunyi', (bool)preg_match('/id="btnAllToken"[^>]*display: none/', $html), true, $lulus);
try {
    app('blade.compiler')->compileString($html);
    periksa('blade terkompilasi', true, true, $lulus);
} catch (\Throwable $e) {
    periksa('blade terkompilasi: ' . $e->getMessage(), false, true, $lulus);
}

echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
