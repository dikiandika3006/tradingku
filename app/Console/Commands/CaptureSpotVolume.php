<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SpotVolumeSnapshot;
use App\Models\TelegramRecipient;
use App\Services\SpotVolumeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Rekam volume spot terakumulasi dari beberapa bursa, sekali sehari.
 *
 * Bursa hanya menyediakan candle beberapa ratus jam ke belakang, jadi riwayat
 * jangka panjang harus dibentuk sendiri lewat rekaman harian.
 *
 * Bila volume harian bergerak melampaui ambang dibanding hari sebelumnya,
 * notifikasi Telegram dikirim.
 */
class CaptureSpotVolume extends Command
{
    protected $signature = 'volume:capture-spot
                            {--symbol=BTC : Aset yang direkam}
                            {--timeframe=24h : Jendela yang dipantau untuk alert (1h, 4h, 12h, 24h, 1w)}
                            {--no-alert : Rekam saja, jangan kirim notifikasi}
                            {--dry-run : Tampilkan hasil tanpa menyimpan maupun mengirim apa pun}';

    protected $description = 'Rekam volume spot harian terakumulasi dari 5 bursa dan kirim alert lonjakan';

    /** Ambang default perubahan volume harian, dalam persen. */
    protected const DEFAULT_THRESHOLD = 50.0;

    protected $volume;

    public function __construct(SpotVolumeService $volume)
    {
        parent::__construct();
        $this->volume = $volume;
    }

    public function handle(): int
    {
        $symbol    = strtoupper(trim($this->option('symbol')));
        $timeframe = strtolower(trim($this->option('timeframe')));

        if (!in_array($timeframe, SpotVolumeSnapshot::TIMEFRAMES, true)) {
            $this->error("Jendela '{$timeframe}' tidak dikenali. Pilihan: "
                . implode(', ', SpotVolumeSnapshot::TIMEFRAMES));
            return self::FAILURE;
        }

        $this->info("Mengambil volume spot {$symbol} dari bursa...");

        $hasil = $this->volume->aggregate($symbol, false);

        if (!$hasil['ok']) {
            $this->error($hasil['message']);
            return self::FAILURE;
        }

        foreach ($hasil['failures'] as $gagal) {
            $this->warn('  Bursa dilewati - ' . $gagal);
        }

        $this->tampilkan($hasil);

        if ($this->option('dry-run')) {
            $this->warn('Mode --dry-run: tidak ada yang disimpan maupun dikirim.');
            return self::SUCCESS;
        }

        // Hari sebelumnya diambil DULU, sebelum hari ini ditulis
        $kemarin = $this->totalHariSebelumnya($symbol);

        $this->simpan($symbol, $hasil);
        $this->info(sprintf('%d bursa direkam untuk tanggal %s.',
            count($hasil['exchanges']), $hasil['fetched_at']->toDateString()));

        if ($this->option('no-alert')) {
            $this->line('Notifikasi dilewati (--no-alert).');
            return self::SUCCESS;
        }

        $this->periksaLonjakan($symbol, $timeframe, $hasil, $kemarin);

        return self::SUCCESS;
    }

    /** Akumulasi volume pada hari rekaman terakhir sebelum hari ini. */
    protected function totalHariSebelumnya(string $symbol): ?array
    {
        $hariIni = now()->toDateString();

        $tanggal = SpotVolumeSnapshot::where('symbol', $symbol)
            ->where('captured_date', '<', $hariIni)
            ->max('captured_date');

        if (!$tanggal) {
            return null;
        }

        $kolom = array_map(fn($tf) => "SUM(volume_usd_{$tf}) AS volume_usd_{$tf}",
            SpotVolumeSnapshot::TIMEFRAMES);

        $baris = SpotVolumeSnapshot::where('symbol', $symbol)
            ->where('captured_date', $tanggal)
            ->selectRaw(implode(', ', $kolom))
            ->first();

        if (!$baris) {
            return null;
        }

        $total = ['tanggal' => $tanggal];
        foreach (SpotVolumeSnapshot::TIMEFRAMES as $tf) {
            $total[$tf] = (float)$baris->{"volume_usd_{$tf}"};
        }

        return $total;
    }

    protected function simpan(string $symbol, array $hasil): void
    {
        $waktu   = $hasil['fetched_at'];
        $tanggal = $waktu->toDateString();

        foreach ($hasil['exchanges'] as $e) {
            SpotVolumeSnapshot::updateOrCreate(
                [
                    'symbol'        => $symbol,
                    'exchange_name' => $e['exchange_name'],
                    'captured_date' => $tanggal,
                ],
                [
                    'pair'           => $e['pair'],
                    'price_usd'      => $e['price_usd'],
                    'volume_usd_1h'  => $e['volume_usd_1h'],
                    'volume_usd_4h'  => $e['volume_usd_4h'],
                    'volume_usd_12h' => $e['volume_usd_12h'],
                    'volume_usd_24h' => $e['volume_usd_24h'],
                    'volume_usd_1w'  => $e['volume_usd_1w'],
                    'captured_at'    => $waktu,
                ]
            );
        }
    }

    protected function periksaLonjakan(string $symbol, string $timeframe, array $hasil, ?array $kemarin): void
    {
        if (!$kemarin) {
            $this->line('Belum ada rekaman hari sebelumnya - alert dilewati kali ini.');
            return;
        }

        if ((Setting::where('key', 'telegram_alert_enabled')->value('value') ?? '1') !== '1') {
            $this->warn('Notifikasi Telegram sedang dimatikan di pengaturan.');
            return;
        }

        $botToken = Setting::where('key', 'telegram_bot_token')->value('value');
        if (empty($botToken)) {
            $this->warn('Token bot Telegram belum diisi.');
            return;
        }

        $penerima = TelegramRecipient::where('is_active', true)->get();
        if ($penerima->isEmpty()) {
            $this->line('Tidak ada penerima Telegram yang aktif.');
            return;
        }

        $ambang = (float)(Setting::where('key', 'spot_volume_alert_threshold')->value('value')
            ?? self::DEFAULT_THRESHOLD);

        $lama = $kemarin[$timeframe] ?? 0.0;
        $baru = $hasil['totals'][$timeframe] ?? 0.0;

        if ($lama <= 0) {
            $this->line('Volume hari sebelumnya nol - perubahan tidak bisa dihitung.');
            return;
        }

        $perubahan = (($baru - $lama) / $lama) * 100.0;
        $label = SpotVolumeSnapshot::TIMEFRAME_LABELS[$timeframe] ?? $timeframe;

        $this->info(sprintf('Perubahan volume %s: %s%.2f%% (ambang %.0f%%)',
            $label, $perubahan >= 0 ? '+' : '', $perubahan, $ambang));

        if (abs($perubahan) < $ambang) {
            $this->line('Di bawah ambang - tidak ada notifikasi.');
            return;
        }

        $pesan = $this->susunPesan($symbol, $label, $hasil, $kemarin, $lama, $baru, $perubahan);

        foreach ($penerima as $p) {
            $this->kirimTelegram($botToken, $p->chat_id, $pesan);
        }

        $this->info('Notifikasi terkirim ke ' . $penerima->count() . ' penerima.');
    }

    protected function susunPesan(string $symbol, string $label, array $hasil,
                                  array $kemarin, float $lama, float $baru, float $perubahan): string
    {
        $arah  = $perubahan >= 0 ? 'LONJAKAN' : 'PENURUNAN';
        $emoji = $perubahan >= 0 ? '🔺' : '🔻';
        $tanda = $perubahan >= 0 ? '+' : '';

        $rincian = '';
        foreach (array_slice($hasil['exchanges'], 0, 5) as $e) {
            $rincian .= sprintf("• %s: *%s*\n", $e['exchange_name'], $this->usd($e['volume_usd_24h']));
        }

        $harga = $hasil['price_usd'] !== null ? '$' . number_format($hasil['price_usd'], 2) : '-';

        return "{$emoji} *{$arah} VOLUME SPOT ({$label})*\n\n"
             . "Aset: *{$symbol}* &mdash; akumulasi " . count($hasil['exchanges']) . " bursa\n"
             . "Harga: *{$harga}*\n\n"
             . "Volume {$kemarin['tanggal']}: *" . $this->usd($lama) . "*\n"
             . "Volume hari ini: *" . $this->usd($baru) . "*\n"
             . "Perubahan: *{$tanda}" . number_format($perubahan, 2) . "%*\n\n"
             . "📊 *Rincian per Bursa (24 Jam):*\n" . $rincian;
    }

    protected function tampilkan(array $hasil): void
    {
        $this->newLine();
        $this->line(sprintf('%-12s %13s %13s %13s %13s %13s',
            'BURSA', '1 Jam', '4 Jam', '12 Jam', '24 Jam', '7 Hari'));
        $this->line(str_repeat('-', 84));

        foreach ($hasil['exchanges'] as $e) {
            $this->line(sprintf('%-12s %13s %13s %13s %13s %13s',
                mb_substr($e['exchange_name'], 0, 12),
                $this->usd($e['volume_usd_1h']), $this->usd($e['volume_usd_4h']),
                $this->usd($e['volume_usd_12h']), $this->usd($e['volume_usd_24h']),
                $this->usd($e['volume_usd_1w'])));
        }

        $this->line(str_repeat('-', 84));
        $t = $hasil['totals'];
        $this->line(sprintf('%-12s %13s %13s %13s %13s %13s',
            'AKUMULASI', $this->usd($t['1h']), $this->usd($t['4h']),
            $this->usd($t['12h']), $this->usd($t['24h']), $this->usd($t['1w'])));
        $this->newLine();
    }

    protected function usd(?float $v): string
    {
        if ($v === null) {
            return '-';
        }
        $a = abs($v);
        if ($a >= 1e9) return '$' . number_format($v / 1e9, 2) . 'B';
        if ($a >= 1e6) return '$' . number_format($v / 1e6, 2) . 'M';
        if ($a >= 1e3) return '$' . number_format($v / 1e3, 2) . 'K';

        return '$' . number_format($v, 2);
    }

    protected function kirimTelegram(string $token, string $chatId, string $pesan): void
    {
        try {
            $response = Http::timeout(6)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id'                  => $chatId,
                'text'                     => $pesan,
                'parse_mode'               => 'Markdown',
                'disable_web_page_preview' => true,
            ]);

            if (!$response->successful()) {
                Log::warning("Telegram sendMessage gagal untuk {$chatId}: " . $response->body());
            }
        } catch (\Throwable $e) {
            Log::error("Gagal mengirim Telegram ke {$chatId}: " . $e->getMessage());
        }
    }
}
