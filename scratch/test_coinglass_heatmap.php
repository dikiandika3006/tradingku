<?php
/**
 * Uji modul heatmap volume CoinGlass, ujung ke ujung.
 *
 * Respons API dipalsukan sehingga seluruh alur bisa diperiksa tanpa memakai API
 * key sungguhan maupun menghabiskan kuota.
 *
 * Catatan: Http::fake() di Laravel MENGGABUNGKAN stub, bukan menggantikannya —
 * stub yang didaftarkan lebih dulu selalu menang. Karena itu dipakai SATU closure
 * yang membaca state yang bisa diubah, supaya tiap fase benar-benar mendapat
 * respons barunya.
 *
 * Semua di dalam transaksi dan di-rollback.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CoinglassVolumeSnapshot;
use App\Models\Setting;
use App\Models\TelegramRecipient;
use App\Services\CoinglassService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$lulus = true;

function periksa(string $label, $dapat, $harus, bool &$lulus): void
{
    $ok = (is_bool($harus) || is_string($harus))
        ? $dapat === $harus
        : (is_numeric($dapat) && abs((float)$dapat - (float)$harus) < 0.01);

    $lulus = $lulus && $ok;

    $tampil = is_bool($dapat) ? var_export($dapat, true)
        : (is_numeric($dapat) ? number_format((float)$dapat, 2) : (string)$dapat);
    $acuan = is_bool($harus) ? var_export($harus, true)
        : (is_numeric($harus) ? number_format((float)$harus, 2) : (string)$harus);

    printf("  [%s] %-44s %20s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label, $tampil, $acuan);
}

/** Bentuk respons sesuai dokumentasi CoinGlass v4. */
function responsApi(array $volumePerBursa): array
{
    $data = [];
    foreach ($volumePerBursa as $bursa => $v) {
        $data[] = [
            'exchange_name'                 => $bursa,
            'symbol'                        => 'BTCUSDT',
            'price_usd'                     => 111250.5,
            'volume_usd_1h'                 => $v,
            'volume_usd_4h'                 => $v * 3.8,
            'volume_usd_12h'                => $v * 11.2,
            'volume_usd_24h'                => $v * 22.0,
            'volume_usd_1w'                 => $v * 150.0,
            'price_change_percent_1h'       => 0.42,
            'price_change_percent_4h'       => -1.15,
            'price_change_percent_12h'      => 2.30,
            'price_change_percent_24h'      => -0.75,
            'price_change_percent_1w'       => 5.60,
            'volume_usd_change_percent_24h' => 12.5,
        ];
    }

    return ['code' => '0', 'msg' => 'success', 'data' => $data];
}

$state = [
    'volumes'  => ['Binance' => 500000000, 'Coinbase' => 120000000, 'OKX' => 80000000],
    'gagal'    => false,
    'telegram' => 0,
];

Http::fake(function ($request) use (&$state) {
    if (str_contains($request->url(), 'api.telegram.org')) {
        $state['telegram']++;
        return Http::response(['ok' => true], 200);
    }

    if ($state['gagal']) {
        return Http::response(['code' => '401', 'msg' => 'API key missing.'], 401);
    }

    return Http::response(responsApi($state['volumes']), 200);
});

DB::beginTransaction();

try {
    CoinglassVolumeSnapshot::query()->delete();
    Setting::where('key', 'like', 'cg_vol_alert_%')->delete();
    Setting::updateOrCreate(['key' => 'coinglass_api_key'], ['value' => 'kunci-uji']);
    Setting::updateOrCreate(['key' => 'coinglass_volume_alert_threshold'], ['value' => '50']);
    Setting::updateOrCreate(['key' => 'telegram_bot_token'], ['value' => 'token-uji']);
    Setting::updateOrCreate(['key' => 'telegram_alert_enabled'], ['value' => '1']);
    TelegramRecipient::updateOrCreate(['chat_id' => '999999'], ['name' => 'Penguji', 'is_active' => true]);
    Cache::forget('coinglass_spot_heatmap_BTC');

    // ---------------------------------------------------------------
    echo "=== UJI 1: parsing respons API ===\n";
    // ---------------------------------------------------------------
    $h = app(CoinglassService::class)->spotVolumeHeatmap('BTC', false);

    periksa('pengambilan berhasil', $h['ok'], true, $lulus);
    periksa('jumlah bursa', count($h['rows']), 3, $lulus);
    periksa('diurutkan volume 24 jam terbesar', $h['rows'][0]['exchange_name'], 'Binance', $lulus);
    periksa('volume 1 jam terbaca', $h['rows'][0]['volume_usd_1h'], 500000000.0, $lulus);
    periksa('volume 7 hari terbaca', $h['rows'][0]['volume_usd_1w'], 75000000000.0, $lulus);
    periksa('perubahan harga 12 jam terbaca', $h['rows'][0]['price_change_percent_12h'], 2.30, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 2: snapshot tersimpan ===\n";
    // ---------------------------------------------------------------
    Artisan::call('coinglass:capture-volume', ['--symbol' => 'BTC', '--no-alert' => true]);

    periksa('baris snapshot tersimpan', CoinglassVolumeSnapshot::count(), 3, $lulus);
    $binance = CoinglassVolumeSnapshot::where('exchange_name', 'Binance')->first();
    periksa('volume 4 jam tersimpan', $binance->volume_usd_4h, 1900000000.0, $lulus);
    periksa('volume 24 jam tersimpan', $binance->volume_usd_24h, 11000000000.0, $lulus);
    $waktu1 = $binance->captured_at;

    // ---------------------------------------------------------------
    echo "\n=== UJI 3: pengambilan kedua tidak menimpa snapshot lama ===\n";
    // ---------------------------------------------------------------
    sleep(1);
    $state['volumes'] = ['Binance' => 560000000, 'Coinbase' => 121000000, 'OKX' => 81000000];
    Artisan::call('coinglass:capture-volume', ['--symbol' => 'BTC', '--no-alert' => true]);

    periksa('total baris jadi 6', CoinglassVolumeSnapshot::count(), 6, $lulus);
    periksa('jumlah snapshot berbeda',
        CoinglassVolumeSnapshot::distinct('captured_at')->count('captured_at'), 2, $lulus);
    periksa('snapshot lama tetap utuh',
        CoinglassVolumeSnapshot::where('captured_at', $waktu1)
            ->where('exchange_name', 'Binance')->value('volume_usd_1h'),
        500000000.0, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 4: alert hanya untuk lonjakan di atas ambang ===\n";
    // ---------------------------------------------------------------
    sleep(1);
    // Binance +100% (memicu), Coinbase +2% dan OKX +1% (tidak)
    $state['volumes'] = ['Binance' => 1120000000, 'Coinbase' => 123420000, 'OKX' => 81810000];
    $state['telegram'] = 0;
    Artisan::call('coinglass:capture-volume', ['--symbol' => 'BTC', '--timeframe' => '1h']);
    $out4 = Artisan::output();

    periksa('tepat 1 notifikasi terkirim', $state['telegram'], 1, $lulus);

    $sebutBinance = str_contains($out4, 'Alert terkirim: Binance');
    $lulus = $lulus && $sebutBinance;
    echo '  [' . ($sebutBinance ? 'OK   ' : 'GAGAL') . "] alert menyebut bursa yang melonjak (Binance)\n";

    $tidakSebutOkx = !str_contains($out4, 'Alert terkirim: OKX');
    $lulus = $lulus && $tidakSebutOkx;
    echo '  [' . ($tidakSebutOkx ? 'OK   ' : 'GAGAL') . "] bursa di bawah ambang tidak dikirimi\n";

    // ---------------------------------------------------------------
    echo "\n=== UJI 5: masa jeda mencegah banjir notifikasi ===\n";
    // ---------------------------------------------------------------
    sleep(1);
    $state['volumes'] = ['Binance' => 2500000000, 'Coinbase' => 123000000, 'OKX' => 81000000];
    $state['telegram'] = 0;
    Artisan::call('coinglass:capture-volume', ['--symbol' => 'BTC', '--timeframe' => '1h']);
    $out5 = Artisan::output();

    periksa('tidak ada notifikasi kedua', $state['telegram'], 0, $lulus);

    $adaJeda = str_contains($out5, 'masa jeda');
    $lulus = $lulus && $adaJeda;
    echo '  [' . ($adaJeda ? 'OK   ' : 'GAGAL') . "] melaporkan alasannya (masa jeda)\n";

    // ---------------------------------------------------------------
    echo "\n=== UJI 6: kegagalan API ditangani, bukan meledak ===\n";
    // ---------------------------------------------------------------
    Cache::forget('coinglass_spot_heatmap_BTC');
    $state['gagal'] = true;

    $gagal = app(CoinglassService::class)->spotVolumeHeatmap('BTC', false);

    periksa('ditandai gagal', $gagal['ok'], false, $lulus);
    periksa('tidak ada baris', count($gagal['rows']), 0, $lulus);

    $adaPesan = is_string($gagal['message']) && str_contains($gagal['message'], '401');
    $lulus = $lulus && $adaPesan;
    echo '  [' . ($adaPesan ? 'OK   ' : 'GAGAL') . "] pesan error menyebut kode HTTP\n";

    // ---------------------------------------------------------------
    echo "\n=== UJI 7: halaman jatuh ke snapshot saat API mati ===\n";
    // ---------------------------------------------------------------
    $view = app(App\Http\Controllers\VolumeHeatmapController::class)
        ->index(new Illuminate\Http\Request(['symbol' => 'BTC']));
    $d = $view->getData();

    periksa('ditandai bukan live', $d['is_live'], false, $lulus);
    periksa('tetap menampilkan baris', count($d['rows']), 3, $lulus);
    periksa('memakai snapshot TERBARU', $d['rows'][0]['volume_usd_1h'], 2500000000.0, $lulus);
    periksa('total 24 jam terhitung', $d['totals']['24h'] > 0, true, $lulus);

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    DB::rollBack();
    Cache::forget('coinglass_spot_heatmap_BTC');
    echo "\n(Data uji di-rollback.)\n";
}
