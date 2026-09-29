<?php
/**
 * Uji mode --explain pada telegram:check-price-alerts.
 *
 * Memastikan tiap jalur keputusan dilaporkan dengan benar, terutama alasan
 * sebuah aset TIDAK masuk notifikasi.
 *
 * Semua di dalam transaksi dan di-rollback.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use App\Models\TelegramPriceAlert;
use App\Services\TokocryptoService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class FakeTokocryptoService extends TokocryptoService
{
    public static array $assets = [];

    public function __construct() {}

    public function getAllPrices(): array
    {
        return [
            'ASTERUSDT' => 0.7084,
            'BTCUSDT'   => 84000.0,
            'USDTIDR'   => 17875.0,
            // GHOST sengaja tidak punya pasangan mana pun
        ];
    }

    public function getPortfolio(): array
    {
        return ['usdt_idr_rate' => 17875.0, 'is_live' => false, 'assets' => self::$assets];
    }
}

function aset(string $nama, float $jumlah, float $harga): array
{
    return ['asset' => $nama, 'free' => $jumlah, 'locked' => 0.0, 'total' => $jumlah,
            'price' => $harga, 'value_usdt' => $jumlah * $harga, 'source' => 'uji'];
}

$lulus = true;

function periksa(string $label, bool $ok, bool &$lulus): void
{
    $lulus = $lulus && $ok;
    printf("  [%s] %s\n", $ok ? 'OK   ' : 'GAGAL', $label);
}

app()->instance(TokocryptoService::class, new FakeTokocryptoService());

DB::beginTransaction();

try {
    TelegramPriceAlert::query()->delete();
    Setting::updateOrCreate(['key' => 'telegram_alert_threshold'], ['value' => '1.5']);
    Setting::updateOrCreate(['key' => 'telegram_alert_enabled'], ['value' => '1']);

    FakeTokocryptoService::$assets = [
        aset('ASTER', 1500.0, 0.7084),   // punya harga, saldo cukup
        aset('GHOST', 100.0, 0.0),       // tidak punya pasangan harga
        aset('DUST',  0.00001, 0.7084),  // saldo terlalu kecil
        aset('USDT',  500.0, 1.0),       // mata uang stabil
    ];

    // ---------------------------------------------------------------
    echo "=== UJI 1: pemeriksaan pertama, patokan belum ada ===\n";
    // ---------------------------------------------------------------
    Artisan::call('telegram:check-price-alerts', ['--explain' => true]);
    $out1 = Artisan::output();

    periksa('ASTER dilaporkan patokan baru dibuat',
        (bool)preg_match('/ASTER.*patokan baru/', $out1), $lulus);
    periksa('GHOST dilaporkan harga tidak ketemu',
        (bool)preg_match('/GHOST.*harga tidak ketemu/', $out1), $lulus);
    periksa('DUST dilaporkan saldo terlalu kecil',
        (bool)preg_match('/DUST.*saldo nol atau terlalu kecil/', $out1), $lulus);
    periksa('USDT dilaporkan mata uang stabil',
        (bool)preg_match('/USDT.*mata uang stabil/', $out1), $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 2: harga bergerak kecil, di bawah ambang ===\n";
    // ---------------------------------------------------------------
    // Patokan disetel 0.7000 -> gerak ke 0.7084 = +1.2%, di bawah ambang 1.5%
    // last_seen_at wajib diisi: tanpa itu patokan dianggap basi lalu disegarkan,
    // bukan dibandingkan. Itu perilaku yang benar sejak perbaikan patokan basi.
    TelegramPriceAlert::updateOrCreate(['symbol' => 'ASTERUSDT'],
        ['last_alert_price' => 0.7000, 'last_alert_time' => now(), 'last_seen_at' => now()]);

    Artisan::call('telegram:check-price-alerts', ['--explain' => true]);
    $out2 = Artisan::output();

    periksa('ASTER dilaporkan belum mencapai ambang',
        (bool)preg_match('/ASTER.*belum mencapai ambang/', $out2), $lulus);
    periksa('persentase geraknya ikut ditampilkan',
        (bool)preg_match('/ASTER.*\+1\.2\d%/', $out2), $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 3: harga bergerak besar, melewati ambang ===\n";
    // ---------------------------------------------------------------
    TelegramPriceAlert::updateOrCreate(['symbol' => 'ASTERUSDT'],
        ['last_alert_price' => 0.6000, 'last_alert_time' => now(), 'last_seen_at' => now()]);

    Artisan::call('telegram:check-price-alerts', ['--explain' => true]);
    $out3 = Artisan::output();

    periksa('ASTER dilaporkan ALERT DIKIRIM',
        (bool)preg_match('/ASTER.*ALERT DIKIRIM/', $out3), $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 4: mode penjelasan tidak mengubah apa pun ===\n";
    // ---------------------------------------------------------------
    $patokanSebelum = TelegramPriceAlert::where('symbol', 'ASTERUSDT')->value('last_alert_price');
    Artisan::call('telegram:check-price-alerts', ['--explain' => true]);
    $patokanSesudah = TelegramPriceAlert::where('symbol', 'ASTERUSDT')->value('last_alert_price');

    periksa('patokan harga tidak diperbarui',
        abs((float)$patokanSebelum - (float)$patokanSesudah) < 0.000001, $lulus);
    periksa('diberi peringatan bahwa tidak ada yang dikirim',
        str_contains($out3, 'tidak ada notifikasi yang dikirim'), $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 5: patokan tanpa last_seen_at dianggap basi ===\n";
    // ---------------------------------------------------------------
    TelegramPriceAlert::updateOrCreate(['symbol' => 'ASTERUSDT'],
        ['last_alert_price' => 0.5000, 'last_alert_time' => now(), 'last_seen_at' => null]);

    Artisan::call('telegram:check-price-alerts', ['--explain' => true]);
    $out5 = Artisan::output();

    periksa('dilaporkan disegarkan, bukan dialerti',
        (bool)preg_match('/ASTER.*DISEGARKAN/', $out5), $lulus);

    echo "\n=== Contoh keluarannya ===\n";
    foreach (explode("\n", $out3) as $baris) {
        if (preg_match('/^(ASET|-{10,}|ASTER|GHOST|DUST|USDT)/', trim($baris))) {
            echo '  ' . rtrim($baris) . "\n";
        }
    }

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    DB::rollBack();
    echo "\n(Data uji di-rollback.)\n";
}
