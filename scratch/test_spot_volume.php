<?php
/**
 * Uji modul volume spot terakumulasi, ujung ke ujung.
 *
 * Respons bursa dipalsukan lewat SATU closure yang membaca state yang bisa
 * diubah — Http::fake() di Laravel menggabungkan stub, bukan menggantikannya,
 * jadi mendaftarkan ulang tidak akan menimpa yang lama.
 *
 * Semua di dalam transaksi dan di-rollback.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use App\Models\SpotVolumeSnapshot;
use App\Models\TelegramRecipient;
use App\Services\SpotVolumeService;
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

    $t = is_bool($dapat) ? var_export($dapat, true)
        : (is_numeric($dapat) ? number_format((float)$dapat, 2) : (string)$dapat);
    $a = is_bool($harus) ? var_export($harus, true)
        : (is_numeric($harus) ? number_format((float)$harus, 2) : (string)$harus);

    printf("  [%s] %-44s %20s (harusnya %s)\n", $ok ? 'OK   ' : 'GAGAL', $label, $t, $a);
}

/**
 * Bangun 169 candle 1 jam dengan volume seragam, lalu bungkus sesuai bentuk
 * masing-masing bursa. Candle indeks 0 selalu jam berjalan.
 */
function candles(float $volPerJam, float $harga, string $bursa, float $faktorLama = 1.0, int $batas = 168): array
{
    $n = 337;
    $out = [];

    for ($i = 0; $i < $n; $i++) {
        $ts = (time() - $i * 3600);

        // Indeks 0 = jam berjalan (dibuang). Candle 1..$batas = periode
        // SEKARANG, sisanya periode SEBELUMNYA yang dikalikan $faktorLama.
        // $batas disetel sesuai jendela yang sedang diuji.
        $v    = $i > $batas ? $volPerJam * $faktorLama : $volPerJam;
        $base = $v / $harga;   // volume dalam BTC

        switch ($bursa) {
            case 'binance':  // [openTime,o,h,l,c,volBase,closeTime,volQuote,...] terlama dulu
                $out[] = [$ts * 1000, $harga, $harga, $harga, $harga, $base, $ts * 1000 + 3599999, $v, 100, 0, 0, 0];
                break;
            case 'bybit':    // [start,o,h,l,c,volBase,turnover] terbaru dulu
                $out[] = [(string)($ts * 1000), "$harga", "$harga", "$harga", "$harga", "$base", "$v"];
                break;
            case 'okx':      // [ts,o,h,l,c,vol,volCcy,volCcyQuote,confirm] terbaru dulu
                $out[] = [(string)($ts * 1000), "$harga", "$harga", "$harga", "$harga", "$base", "$v", "$v", '1'];
                break;
            case 'kraken':   // [time,o,h,l,c,vwap,volBase,count] terlama dulu
                $out[] = [$ts, "$harga", "$harga", "$harga", "$harga", "$harga", "$base", 50];
                break;
            case 'coinbase': // [time,low,high,open,close,volBase] terbaru dulu
                $out[] = [$ts, $harga, $harga, $harga, $harga, $base];
                break;
        }
    }

    // Binance & Kraken mengirim terlama dulu
    if (in_array($bursa, ['binance', 'kraken'], true)) {
        $out = array_reverse($out);
    }

    return $out;
}

$state = [
    // volume USD per jam, per bursa
    'vol'      => ['binance' => 1000000.0, 'bybit' => 500000.0, 'okx' => 400000.0,
                   'kraken' => 100000.0, 'coinbase' => 300000.0],
    'mati'     => [],       // bursa yang disimulasikan gagal
    'lama'     => 1.0,      // pengali volume periode SEBELUMNYA
    'batas'    => 168,      // candle ke berapa periode sekarang berakhir
    'telegram' => 0,
];

$harga = 80000.0;

Http::fake(function ($request) use (&$state, $harga) {
    $url = $request->url();

    if (str_contains($url, 'api.telegram.org')) {
        $state['telegram']++;
        return Http::response(['ok' => true], 200);
    }

    foreach (['binance' => 'api.binance.com', 'bybit' => 'api.bybit.com', 'okx' => 'okx.com',
              'kraken' => 'api.kraken.com', 'coinbase' => 'exchange.coinbase.com'] as $nama => $host) {
        if (!str_contains($url, $host)) {
            continue;
        }

        if (in_array($nama, $state['mati'], true)) {
            return Http::response(['error' => 'down'], 503);
        }

        $c = candles($state['vol'][$nama], $harga, $nama, $state['lama'], $state['batas']);

        if ($nama === 'binance')  return Http::response($c, 200);
        if ($nama === 'bybit')    return Http::response(['result' => ['list' => $c]], 200);
        if ($nama === 'okx')      return Http::response(['data' => $c], 200);
        if ($nama === 'kraken')   return Http::response(['result' => ['XBTUSDT' => $c, 'last' => 0]], 200);
        if ($nama === 'coinbase') return Http::response($c, 200);
    }

    return Http::response([], 404);
});

DB::beginTransaction();

try {
    SpotVolumeSnapshot::query()->delete();
    Setting::updateOrCreate(['key' => 'spot_volume_alert_threshold'], ['value' => '50']);
    Setting::updateOrCreate(['key' => 'telegram_bot_token'], ['value' => 'token-uji']);
    Setting::updateOrCreate(['key' => 'telegram_alert_enabled'], ['value' => '1']);
    TelegramRecipient::updateOrCreate(['chat_id' => '999999'], ['name' => 'Penguji', 'is_active' => true]);
    Cache::forget('spot_volume_aggregate_BTC');

    $volAwal     = $state['vol'];              // acuan tetap lintas fase uji
    $totalPerJam = array_sum($state['vol']);   // 2.300.000

    // ---------------------------------------------------------------
    echo "=== UJI 1: kelima bursa dinormalkan ke USD dengan benar ===\n";
    // ---------------------------------------------------------------
    $h = app(SpotVolumeService::class)->aggregate('BTC', false);

    periksa('pengambilan berhasil', $h['ok'], true, $lulus);
    periksa('jumlah bursa', count($h['exchanges']), 5, $lulus);

    $perBursa = collect($h['exchanges'])->keyBy('exchange_name');
    // Kraken & Coinbase hanya memberi volume BTC; harus dikalikan harga
    periksa('Kraken dikonversi ke USD', $perBursa['Kraken']['volume_usd_1h'], 100000.0, $lulus);
    periksa('Coinbase dikonversi ke USD', $perBursa['Coinbase']['volume_usd_1h'], 300000.0, $lulus);
    periksa('Binance memakai quote langsung', $perBursa['Binance']['volume_usd_1h'], 1000000.0, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 2: jendela waktu dijumlahkan benar ===\n";
    // ---------------------------------------------------------------
    periksa('akumulasi 1 jam', $h['totals']['1h'], $totalPerJam, $lulus);
    periksa('akumulasi 4 jam', $h['totals']['4h'], $totalPerJam * 4, $lulus);
    periksa('akumulasi 12 jam', $h['totals']['12h'], $totalPerJam * 12, $lulus);
    periksa('akumulasi 24 jam', $h['totals']['24h'], $totalPerJam * 24, $lulus);
    periksa('akumulasi 7 hari', $h['totals']['1w'], $totalPerJam * 168, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 3: candle jam berjalan dibuang ===\n";
    // ---------------------------------------------------------------
    // 337 candle dikirim; yang terpakai harus 336 (dua periode penuh)
    periksa('candle terpakai per bursa', $perBursa['Binance']['candle_count'], 336, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 3b: perubahan volume vs periode sebelumnya ===\n";
    // ---------------------------------------------------------------
    // Tiap jendela diuji sendiri: periode sebelumnya dibuat setengahnya,
    // jadi perubahannya harus tepat +100%.
    $state['lama'] = 0.5;

    foreach (['1h' => 1, '4h' => 4, '12h' => 12, '24h' => 24, '1w' => 168] as $tf => $jam) {
        $state['batas'] = $jam;
        Cache::forget('spot_volume_aggregate_BTC');

        $hx = app(SpotVolumeService::class)->aggregate('BTC', false);
        $bx = collect($hx['exchanges'])->keyBy('exchange_name');

        periksa("perubahan {$tf} Binance", $bx['Binance']["volume_change_percent_{$tf}"], 100.0, $lulus);
        periksa("perubahan {$tf} akumulasi", $hx['changes'][$tf], 100.0, $lulus);
        // Kraken memberi volume dalam BTC; pastikan konversinya tidak merusak persentase
        periksa("perubahan {$tf} Kraken", $bx['Kraken']["volume_change_percent_{$tf}"], 100.0, $lulus);
    }

    // Periode sebelumnya dua kali lipat -> sekarang harus -50%
    $state['lama']  = 2.0;
    $state['batas'] = 24;
    Cache::forget('spot_volume_aggregate_BTC');

    $hTurun = app(SpotVolumeService::class)->aggregate('BTC', false);
    periksa('volume menyusut terbaca negatif', $hTurun['changes']['24h'], -50.0, $lulus);

    $state['lama']  = 1.0;
    $state['batas'] = 168;

    // ---------------------------------------------------------------
    echo "\n=== UJI 4: bursa mati dilewati, sisanya tetap jalan ===\n";
    // ---------------------------------------------------------------
    Cache::forget('spot_volume_aggregate_BTC');
    $state['mati'] = ['kraken', 'coinbase'];

    $h2 = app(SpotVolumeService::class)->aggregate('BTC', false);
    periksa('tetap berhasil', $h2['ok'], true, $lulus);
    periksa('bursa tersisa', count($h2['exchanges']), 3, $lulus);
    periksa('kegagalan dicatat', count($h2['failures']), 2, $lulus);
    periksa('akumulasi menyesuaikan', $h2['totals']['1h'], 1900000.0, $lulus);

    $state['mati'] = [];

    // ---------------------------------------------------------------
    echo "\n=== UJI 5: rekaman harian, satu baris per bursa per hari ===\n";
    // ---------------------------------------------------------------
    Cache::forget('spot_volume_aggregate_BTC');
    Artisan::call('volume:capture-spot', ['--symbol' => 'BTC', '--no-alert' => true]);

    periksa('baris tersimpan', SpotVolumeSnapshot::count(), 5, $lulus);

    // Jalankan lagi di hari yang sama — harus memperbarui, bukan menambah
    Cache::forget('spot_volume_aggregate_BTC');
    Artisan::call('volume:capture-spot', ['--symbol' => 'BTC', '--no-alert' => true]);

    periksa('dijalankan ulang tidak menambah baris', SpotVolumeSnapshot::count(), 5, $lulus);
    periksa('hari terekam', SpotVolumeSnapshot::distinct('captured_date')->count('captured_date'), 1, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 6: akumulasi harian bisa dijumlahkan kembali ===\n";
    // ---------------------------------------------------------------
    $harian = SpotVolumeSnapshot::dailyTotals('BTC', 7);
    periksa('satu baris harian', $harian->count(), 1, $lulus);
    periksa('jumlah bursa per hari', $harian->first()->exchange_count, 5, $lulus);
    periksa('total 24 jam cocok', $harian->first()->volume_usd_24h, $totalPerJam * 24, $lulus);

    // ---------------------------------------------------------------
    echo "\n=== UJI 7: alert saat volume harian melonjak ===\n";
    // ---------------------------------------------------------------
    // Geser rekaman hari ini jadi kemarin, lalu naikkan volume 100%
    SpotVolumeSnapshot::query()->update(['captured_date' => now()->subDay()->toDateString()]);

    $state['vol'] = array_map(fn($v) => $v * 2, $state['vol']);
    $state['telegram'] = 0;
    Cache::forget('spot_volume_aggregate_BTC');

    Artisan::call('volume:capture-spot', ['--symbol' => 'BTC', '--timeframe' => '24h']);
    $out = Artisan::output();

    periksa('notifikasi terkirim', $state['telegram'], 1, $lulus);
    periksa('dua hari terekam', SpotVolumeSnapshot::distinct('captured_date')->count('captured_date'), 2, $lulus);

    $sebut100 = str_contains($out, '+100.00%');
    $lulus = $lulus && $sebut100;
    echo '  [' . ($sebut100 ? 'OK   ' : 'GAGAL') . "] perubahan dilaporkan +100.00%\n";

    // ---------------------------------------------------------------
    echo "\n=== UJI 8: di bawah ambang tidak mengirim apa pun ===\n";
    // ---------------------------------------------------------------
    SpotVolumeSnapshot::where('captured_date', now()->toDateString())->delete();
    SpotVolumeSnapshot::query()->update(['captured_date' => now()->subDay()->toDateString()]);

    // Baris kemarin menyimpan volume AWAL, jadi acuannya harus itu — bukan
    // nilai yang sudah digandakan pada UJI 7.
    $state['vol'] = array_map(fn($v) => $v * 1.05, $volAwal);   // +5% terhadap kemarin
    $state['telegram'] = 0;
    Cache::forget('spot_volume_aggregate_BTC');

    Artisan::call('volume:capture-spot', ['--symbol' => 'BTC', '--timeframe' => '24h']);
    $out8 = Artisan::output();

    periksa('tidak ada notifikasi', $state['telegram'], 0, $lulus);
    $sebutAmbang = str_contains($out8, 'Di bawah ambang');
    $lulus = $lulus && $sebutAmbang;
    echo '  [' . ($sebutAmbang ? 'OK   ' : 'GAGAL') . "] melaporkan alasannya\n";

    // ---------------------------------------------------------------
    echo "\n=== UJI 9: halaman menampilkan akumulasi & riwayat ===\n";
    // ---------------------------------------------------------------
    Cache::forget('spot_volume_aggregate_BTC');
    $view = app(App\Http\Controllers\VolumeHeatmapController::class)
        ->index(new Illuminate\Http\Request(['symbol' => 'BTC']));
    $d = $view->getData();

    periksa('halaman live', $d['is_live'], true, $lulus);
    periksa('bursa tampil', count($d['exchanges']), 5, $lulus);
    periksa('akumulasi 24 jam ada', $d['totals']['24h'] > 0, true, $lulus);
    periksa('riwayat harian terisi', $d['daily']->count() >= 1, true, $lulus);

    echo "\n" . ($lulus ? '>>> SEMUA UJI LULUS' : '>>> ADA UJI YANG GAGAL') . "\n";
} finally {
    DB::rollBack();
    Cache::forget('spot_volume_aggregate_BTC');
    echo "\n(Data uji di-rollback.)\n";
}
