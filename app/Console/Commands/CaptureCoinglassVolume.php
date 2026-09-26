<?php

namespace App\Console\Commands;

use App\Models\CoinglassVolumeSnapshot;
use App\Models\Setting;
use App\Models\TelegramRecipient;
use App\Services\CoinglassService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Rekam heatmap volume spot CoinGlass, lalu kirim alert bila ada lonjakan.
 *
 * API CoinGlass hanya memberi kondisi saat ini — volume 4 jam kemarin tidak bisa
 * diminta lagi besok. Karena itu setiap pengambilan disimpan sebagai snapshot,
 * supaya riwayatnya terbentuk sendiri seiring waktu.
 */
class CaptureCoinglassVolume extends Command
{
    protected $signature = 'coinglass:capture-volume
                            {--symbol=BTC : Aset yang direkam}
                            {--timeframe=1h : Timeframe yang dipantau untuk alert (1h, 4h, 12h, 24h, 1w)}
                            {--no-alert : Rekam saja, jangan kirim notifikasi}
                            {--dry-run : Tampilkan hasil tanpa menyimpan maupun mengirim apa pun}';

    protected $description = 'Rekam heatmap volume spot dari CoinGlass dan kirim alert lonjakan volume';

    /** Ambang default lonjakan volume, dalam persen. */
    protected const DEFAULT_THRESHOLD = 50.0;

    /** Bursa dengan volume di bawah ini diabaikan supaya tidak berisik. */
    protected const MIN_VOLUME_USD = 1_000_000.0;

    /** Jeda minimal antar alert untuk bursa yang sama, dalam menit. */
    protected const COOLDOWN_MINUTES = 180;

    protected $coinglass;

    public function __construct(CoinglassService $coinglass)
    {
        parent::__construct();
        $this->coinglass = $coinglass;
    }

    public function handle(): int
    {
        $symbol    = strtoupper(trim($this->option('symbol')));
        $timeframe = strtolower(trim($this->option('timeframe')));
        $dryRun    = (bool)$this->option('dry-run');

        if (!in_array($timeframe, CoinglassVolumeSnapshot::TIMEFRAMES, true)) {
            $this->error("Timeframe '{$timeframe}' tidak dikenali. Pilihan: "
                . implode(', ', CoinglassVolumeSnapshot::TIMEFRAMES));
            return self::FAILURE;
        }

        $this->info("Mengambil heatmap volume {$symbol} dari CoinGlass...");

        $hasil = $this->coinglass->spotVolumeHeatmap($symbol, false);

        if (!$hasil['ok']) {
            $this->error($hasil['message']);
            return self::FAILURE;
        }

        $rows = $hasil['rows'];
        $capturedAt = $hasil['fetched_at'];

        $this->info(sprintf('%d bursa diterima.', count($rows)));

        // Snapshot sebelumnya dipakai sebagai pembanding lonjakan
        $sebelumnya = $this->previousCapture($symbol);

        if ($dryRun) {
            $this->tampilkan($rows, $timeframe);
            $this->warn('Mode --dry-run: tidak ada yang disimpan maupun dikirim.');
            return self::SUCCESS;
        }

        $disimpan = $this->simpan($rows, $capturedAt);
        $this->info("{$disimpan} baris snapshot disimpan pada {$capturedAt->toDateTimeString()}.");

        if ($this->option('no-alert')) {
            $this->line('Notifikasi dilewati (--no-alert).');
            return self::SUCCESS;
        }

        $this->periksaLonjakan($symbol, $timeframe, $rows, $sebelumnya);

        return self::SUCCESS;
    }

    /**
     * Ambil pengambilan terakhir sebagai pembanding, dipetakan per bursa.
     *
     * @return array<string, CoinglassVolumeSnapshot>
     */
    protected function previousCapture(string $symbol): array
    {
        $terakhir = CoinglassVolumeSnapshot::where('symbol', $symbol)->max('captured_at');

        if (!$terakhir) {
            return [];
        }

        return CoinglassVolumeSnapshot::where('symbol', $symbol)
            ->where('captured_at', $terakhir)
            ->get()
            ->keyBy(fn($r) => $this->kunci($r->exchange_name, $r->pair))
            ->all();
    }

    /** @param array<int, array> $rows */
    protected function simpan(array $rows, $capturedAt): int
    {
        $n = 0;

        foreach ($rows as $row) {
            CoinglassVolumeSnapshot::updateOrCreate(
                [
                    'symbol'        => $row['symbol'],
                    'exchange_name' => $row['exchange_name'],
                    'pair'          => $row['pair'],
                    'captured_at'   => $capturedAt,
                ],
                [
                    'price_usd'                 => $row['price_usd'],
                    'volume_usd_1h'             => $row['volume_usd_1h'],
                    'volume_usd_4h'             => $row['volume_usd_4h'],
                    'volume_usd_12h'            => $row['volume_usd_12h'],
                    'volume_usd_24h'            => $row['volume_usd_24h'],
                    'volume_usd_1w'             => $row['volume_usd_1w'],
                    'price_change_percent_1h'   => $row['price_change_percent_1h'],
                    'price_change_percent_4h'   => $row['price_change_percent_4h'],
                    'price_change_percent_12h'  => $row['price_change_percent_12h'],
                    'price_change_percent_24h'  => $row['price_change_percent_24h'],
                    'price_change_percent_1w'   => $row['price_change_percent_1w'],
                    'volume_change_percent_24h' => $row['volume_change_percent_24h'],
                ]
            );
            $n++;
        }

        return $n;
    }

    /**
     * Bandingkan volume terhadap pengambilan sebelumnya, kirim alert bila melonjak.
     *
     * @param array<int, array> $rows
     * @param array<string, CoinglassVolumeSnapshot> $sebelumnya
     */
    protected function periksaLonjakan(string $symbol, string $timeframe, array $rows, array $sebelumnya): void
    {
        if (empty($sebelumnya)) {
            $this->line('Belum ada snapshot pembanding — alert dilewati kali ini.');
            return;
        }

        $aktif = (Setting::where('key', 'telegram_alert_enabled')->value('value') ?? '1') === '1';
        if (!$aktif) {
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

        $ambang = (float)(Setting::where('key', 'coinglass_volume_alert_threshold')->value('value')
            ?? self::DEFAULT_THRESHOLD);

        $label = CoinglassVolumeSnapshot::TIMEFRAME_LABELS[$timeframe] ?? $timeframe;
        $this->info("Memeriksa lonjakan volume {$label} dengan ambang {$ambang}%...");

        $terkirim = 0;

        foreach ($rows as $row) {
            $kunci = $this->kunci($row['exchange_name'], $row['pair']);
            $lama  = $sebelumnya[$kunci] ?? null;

            if (!$lama) {
                continue;
            }

            $volumeBaru = $row["volume_usd_{$timeframe}"] ?? null;
            $volumeLama = $lama->volumeFor($timeframe);

            // Perlu dua angka yang sah, dan volume yang cukup berarti
            if ($volumeBaru === null || $volumeLama === null || $volumeLama <= 0) {
                continue;
            }

            if ($volumeBaru < self::MIN_VOLUME_USD) {
                continue;
            }

            $perubahan = (($volumeBaru - $volumeLama) / $volumeLama) * 100.0;

            if (abs($perubahan) < $ambang) {
                continue;
            }

            if ($this->masihDalamJeda($symbol, $row['exchange_name'], $timeframe)) {
                $this->line("  {$row['exchange_name']}: melonjak tapi masih dalam masa jeda, dilewati.");
                continue;
            }

            $pesan = $this->susunPesan($symbol, $label, $row, $volumeLama, $volumeBaru, $perubahan);

            foreach ($penerima as $p) {
                $this->kirimTelegram($botToken, $p->chat_id, $pesan);
            }

            $this->tandaiTerkirim($symbol, $row['exchange_name'], $timeframe);
            $terkirim++;

            $this->info(sprintf('  Alert terkirim: %s %s%.1f%%',
                $row['exchange_name'], $perubahan >= 0 ? '+' : '', $perubahan));
        }

        $this->info($terkirim > 0
            ? "{$terkirim} alert lonjakan volume terkirim."
            : 'Tidak ada lonjakan yang melewati ambang.');
    }

    protected function susunPesan(string $symbol, string $label, array $row,
                                  float $lama, float $baru, float $perubahan): string
    {
        $arah = $perubahan >= 0 ? 'LONJAKAN' : 'PENURUNAN';
        $emoji = $perubahan >= 0 ? '🔺' : '🔻';
        $tanda = $perubahan >= 0 ? '+' : '';

        $harga = $row['price_usd'] !== null
            ? '$' . number_format($row['price_usd'], 2)
            : '-';

        $ubahHarga = $row["price_change_percent_{$this->option('timeframe')}"] ?? null;
        $ubahHargaTeks = $ubahHarga !== null
            ? sprintf('%s%.2f%%', $ubahHarga >= 0 ? '+' : '', $ubahHarga)
            : '-';

        return "{$emoji} *{$arah} VOLUME SPOT ({$label})*\n\n"
             . "Aset: *{$symbol}*\n"
             . "Bursa: *{$row['exchange_name']}*" . ($row['pair'] ? " (`{$row['pair']}`)" : '') . "\n\n"
             . "Volume Sebelumnya: *" . $this->usd($lama) . "*\n"
             . "Volume Sekarang: *" . $this->usd($baru) . "*\n"
             . "Perubahan: *{$tanda}" . number_format($perubahan, 2) . "%*\n\n"
             . "Harga: *{$harga}* ({$ubahHargaTeks} dalam {$label})\n\n"
             . "_Dibandingkan terhadap pengambilan sebelumnya, bukan awal timeframe._";
    }

    /** Cegah bursa yang sama membanjiri notifikasi. */
    protected function masihDalamJeda(string $symbol, string $exchange, string $timeframe): bool
    {
        $kunci = $this->kunciJeda($symbol, $exchange, $timeframe);
        $waktu = Setting::where('key', $kunci)->value('value');

        if (!$waktu) {
            return false;
        }

        return now()->diffInMinutes(\Carbon\Carbon::parse($waktu)) < self::COOLDOWN_MINUTES;
    }

    protected function tandaiTerkirim(string $symbol, string $exchange, string $timeframe): void
    {
        Setting::updateOrCreate(
            ['key' => $this->kunciJeda($symbol, $exchange, $timeframe)],
            ['value' => now()->toDateTimeString()]
        );
    }

    protected function kunciJeda(string $symbol, string $exchange, string $timeframe): string
    {
        return 'cg_vol_alert_' . strtolower($symbol . '_' . preg_replace('/[^A-Za-z0-9]/', '', $exchange) . '_' . $timeframe);
    }

    protected function kunci(string $exchange, ?string $pair): string
    {
        return strtolower($exchange . '|' . ($pair ?? ''));
    }

    /** @param array<int, array> $rows */
    protected function tampilkan(array $rows, string $timeframe): void
    {
        $this->newLine();
        $this->line(sprintf('%-18s %16s %16s %16s %16s %16s',
            'BURSA', '1 Jam', '4 Jam', '12 Jam', '24 Jam', '7 Hari'));
        $this->line(str_repeat('-', 104));

        foreach (array_slice($rows, 0, 20) as $row) {
            $this->line(sprintf('%-18s %16s %16s %16s %16s %16s',
                mb_substr($row['exchange_name'], 0, 18),
                $this->usd($row['volume_usd_1h']),
                $this->usd($row['volume_usd_4h']),
                $this->usd($row['volume_usd_12h']),
                $this->usd($row['volume_usd_24h']),
                $this->usd($row['volume_usd_1w'])));
        }

        if (count($rows) > 20) {
            $this->line(sprintf('... dan %d bursa lainnya.', count($rows) - 20));
        }
        $this->newLine();
    }

    protected function usd(?float $v): string
    {
        if ($v === null) {
            return '-';
        }
        if (abs($v) >= 1_000_000_000) {
            return '$' . number_format($v / 1_000_000_000, 2) . 'B';
        }
        if (abs($v) >= 1_000_000) {
            return '$' . number_format($v / 1_000_000, 2) . 'M';
        }
        if (abs($v) >= 1_000) {
            return '$' . number_format($v / 1_000, 2) . 'K';
        }

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
