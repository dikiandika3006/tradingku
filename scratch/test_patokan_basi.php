<?php
/**
 * Uji penyegaran patokan harga yang basi, dan mode --explain yang tidak menulis.
 *
 * PENTING: uji ini memakai SQLite di memori, BUKAN database aplikasi. Aman
 * dijalankan meski .env sedang mengarah ke produksi — tidak ada satu pun query
 * yang menyentuh database sungguhan.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use App\Models\TelegramPriceAlert;
use App\Models\TelegramRecipient;
use App\Services\TokocryptoService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

// ---- Alihkan SELURUH akses database ke SQLite di memori ----
Config::set('database.connections.uji', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
Config::set('database.default', 'uji');
DB::purge('uji');

$aman = config('database.default') === 'uji'
    && config('database.connections.uji.database') === ':memory:';
echo $aman
    ? "Koneksi uji: SQLite di memori — database produksi TIDAK tersentuh.\n\n"
    : "BERHENTI: koneksi uji gagal dialihkan.\n";
if (!$aman) { exit(1); }

Schema::create('settings', function ($t) { $t->id(); $t->string('key')->unique(); $t->text('value')->nullable(); $t->timestamps(); });
Schema::create('telegram_recipients', function ($t) { $t->id(); $t->string('name')->nullable(); $t->string('chat_id'); $t->boolean('is_active')->default(true); $t->timestamps(); });
Schema::create('trades', function ($t) { $t->id(); $t->string('symbol'); $t->string('type'); $t->decimal('price', 24, 8); $t->decimal('amount', 24, 8); $t->decimal('total', 24, 8); $t->dateTime('trade_time'); $t->text('notes')->nullable(); $t->timestamps(); });
Schema::create('telegram_price_alerts', function ($t) { $t->id(); $t->string('symbol')->unique(); $t->double('last_alert_price'); $t->timestamp('last_alert_time')->nullable(); $t->timestamp('last_seen_at')->nullable(); $t->timestamps(); });

class FakeTokocryptoService extends TokocryptoService
{
    public static float $harga = 0.7084;
    public function __construct() {}
    public function getAllPrices(): array { return ['ASTERUSDT' => self::$harga, 'USDTIDR' => 17875.0]; }
    public function getPortfolio(): array
    {
        return ['usdt_idr_rate' => 17875.0, 'is_live' => false, 'assets' => [
            ['asset' => 'ASTER', 'free' => 152.0, 'locked' => 0.0, 'total' => 152.0,
             'price' => self::$harga, 'value_usdt' => 152.0 * self::$harga, 'source' => 'uji'],
        ]];
    }
}
app()->instance(TokocryptoService::class, new FakeTokocryptoService());

$terkirim = 0;
Http::fake(function () use (&$terkirim) { $terkirim++; return Http::response(['ok' => true], 200); });

$lulus = true;
function periksa(string $label, $dapat, $harus, bool &$lulus): void
{
    $ok = is_float($harus) ? (is_numeric($dapat) && abs((float)$dapat - $harus) < 0.0001) : $dapat === $harus;
    $lulus = $lulus && $ok;
    printf("  [%s] %-46s %14s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label,
        is_bool($dapat) ? var_export($dapat, true) : (string)$dapat,
        is_bool($harus) ? var_export($harus, true) : (string)$harus);
}

Setting::create(['key' => 'telegram_alert_enabled', 'value' => '1']);
Setting::create(['key' => 'telegram_alert_threshold', 'value' => '1.5']);
Setting::create(['key' => 'telegram_bot_token', 'value' => 'token-uji']);
TelegramRecipient::create(['name' => 'Penguji', 'chat_id' => '999', 'is_active' => true]);

// ---------------------------------------------------------------
echo "=== UJI 1: patokan basi (aset dijual lalu dibeli lagi) ===\n";
// ---------------------------------------------------------------
// Meniru BNB di produksi: patokan lama, terakhir terlihat 2 bulan lalu
TelegramPriceAlert::create(['symbol' => 'ASTERUSDT', 'last_alert_price' => 0.5000,
    'last_alert_time' => now()->subMonths(2), 'last_seen_at' => now()->subMonths(2)]);

$terkirim = 0;
Artisan::call('telegram:check-price-alerts');
$out1 = Artisan::output();
$a = TelegramPriceAlert::first();

periksa('tidak mengirim alert palsu', $terkirim, 0, $lulus);
periksa('patokan disegarkan ke harga sekarang', (float)$a->last_alert_price, 0.7084, $lulus);
periksa('waktu terlihat ikut diperbarui', $a->last_seen_at->isToday(), true, $lulus);
$sebut = str_contains($out1, 'disegarkan');
$lulus = $lulus && $sebut;
echo '  [' . ($sebut ? 'OK   ' : 'GAGAL') . "] alasannya dilaporkan di keluaran\n";

// ---------------------------------------------------------------
echo "\n=== UJI 2: aset yang memang dipegang tetap dialerti ===\n";
// ---------------------------------------------------------------
// Terlihat 10 menit lalu (masih segar), harga melonjak +41%
TelegramPriceAlert::query()->update(['last_alert_price' => 0.5000,
    'last_alert_time' => now()->subHours(3), 'last_seen_at' => now()->subMinutes(10)]);

$terkirim = 0;
Artisan::call('telegram:check-price-alerts');
periksa('alert sungguhan tetap terkirim', $terkirim, 1, $lulus);
periksa('patokan mengikuti harga baru', (float)TelegramPriceAlert::first()->last_alert_price, 0.7084, $lulus);

// ---------------------------------------------------------------
echo "\n=== UJI 3: gerak kecil tetap tidak mengirim ===\n";
// ---------------------------------------------------------------
TelegramPriceAlert::query()->update(['last_alert_price' => 0.7000,
    'last_alert_time' => now()->subHour(), 'last_seen_at' => now()->subMinutes(5)]);

$terkirim = 0;
Artisan::call('telegram:check-price-alerts');
periksa('tidak mengirim (gerak +1.2%)', $terkirim, 0, $lulus);
periksa('patokan tidak diubah', (float)TelegramPriceAlert::first()->last_alert_price, 0.7000, $lulus);
periksa('tapi waktu terlihat diperbarui', TelegramPriceAlert::first()->last_seen_at->isToday(), true, $lulus);

// ---------------------------------------------------------------
echo "\n=== UJI 4: --explain benar-benar tidak menulis ===\n";
// ---------------------------------------------------------------
$sebelum = TelegramPriceAlert::first()->toArray();
$terkirim = 0;
Artisan::call('telegram:check-price-alerts', ['--explain' => true]);
$sesudah = TelegramPriceAlert::first()->toArray();

periksa('tidak mengirim apa pun', $terkirim, 0, $lulus);
periksa('patokan tidak berubah', $sesudah['last_alert_price'], $sebelum['last_alert_price'], $lulus);
periksa('waktu terlihat tidak berubah', $sesudah['last_seen_at'], $sebelum['last_seen_at'], $lulus);

// Aset baru: --explain tidak boleh membuat baris patokan
TelegramPriceAlert::query()->delete();
Artisan::call('telegram:check-price-alerts', ['--explain' => true]);
periksa('tidak membuat patokan baru', TelegramPriceAlert::count(), 0, $lulus);

// Tanpa --explain, barulah dibuat
Artisan::call('telegram:check-price-alerts');
periksa('tanpa --explain patokan dibuat', TelegramPriceAlert::count(), 1, $lulus);

echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
echo "\n(Tidak ada query yang menyentuh database produksi.)\n";
