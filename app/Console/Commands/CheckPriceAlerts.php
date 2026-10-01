<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Setting;
use App\Models\TelegramRecipient;
use App\Models\TelegramPriceAlert;
use App\Services\TokocryptoService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckPriceAlerts extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'telegram:check-price-alerts
                            {--explain : Laporkan keputusan untuk TIAP aset tanpa mengirim notifikasi}';

    /**
     * The console command description.
     */
    protected $description = 'Check portfolio asset prices and send Telegram alerts for movements >= 2%';

    /**
     * Jeda maksimum sebuah aset boleh tidak terlihat sebelum patokannya dianggap basi.
     *
     * Pemeriksaan berjalan tiap menit, jadi aset yang benar-benar dipegang akan
     * terlihat terus. Jeda sepanjang ini berarti asetnya sempat dijual, atau cron
     * mati lama — dua-duanya membuat perbandingan terhadap patokan lama menyesatkan.
     */
    protected const JEDA_BASI_JAM = 6;

    protected $tokocryptoService;

    protected $costBasis;

    public function __construct(
        TokocryptoService $tokocryptoService,
        \App\Services\CostBasisService $costBasis
    ) {
        parent::__construct();
        $this->tokocryptoService = $tokocryptoService;
        $this->costBasis = $costBasis;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Mode penjelasan tidak mengirim apa pun, jadi penjaga pengiriman dilewati.
        // Tanpa ini, masalah pada token atau penerima menutupi penyebab sebenarnya
        // kenapa sebuah aset tidak pernah dialerti.
        $jelaskan = (bool)$this->option('explain');

        $enabled = Setting::where('key', 'telegram_alert_enabled')->value('value') ?? '1';
        if ($enabled !== '1' && !$jelaskan) {
            $this->warn('Telegram price alerts are currently disabled in settings.');
            return 0;
        }

        $botToken = Setting::where('key', 'telegram_bot_token')->value('value');
        if (empty($botToken) && !$jelaskan) {
            $this->warn('Telegram Bot Token is not configured.');
            return 0;
        }

        $recipients = TelegramRecipient::where('is_active', true)->get();
        if ($recipients->isEmpty() && !$jelaskan) {
            $this->info('No active Telegram recipients registered.');
            return 0;
        }

        if ($jelaskan) {
            $this->line('Alert aktif      : ' . ($enabled === '1' ? 'ya' : 'TIDAK'));
            $this->line('Token bot terisi : ' . (empty($botToken) ? 'TIDAK' : 'ya'));
            $this->line('Penerima aktif   : ' . $recipients->count());
        }

        $threshold = (float)(Setting::where('key', 'telegram_alert_threshold')->value('value') ?? 2.0);
        $this->info("Starting price alert check. Threshold: {$threshold}%");

        // Fetch current portfolio balances and market prices
        $portfolio = $this->tokocryptoService->getPortfolio();
        $prices = $this->tokocryptoService->getAllPrices();
        $usdtIdr = $portfolio['usdt_idr_rate'] ?? 16000.0;

        $cashSymbols = ['USDT', 'BIDR', 'IDRT', 'BUSD', 'USDC', 'BTC', 'ETH', 'BNB', 'IDR'];

        // Catatan keputusan per aset, dipakai oleh --explain
        $laporan = [];

        foreach ($portfolio['assets'] as $assetData) {
            $asset = strtoupper($assetData['asset']);
            $totalBalance = (float)$assetData['total'];

            // Skip stable cash currencies
            if (in_array($asset, ['USDT', 'BIDR', 'IDRT', 'BUSD', 'USDC', 'IDR'])) {
                $laporan[] = [$asset, number_format($totalBalance, 4), '-', '-', '-', 'DILEWATI: mata uang stabil/fiat'];
                continue;
            }

            if ($totalBalance <= 0.0001) {
                $laporan[] = [$asset, number_format($totalBalance, 8), '-', '-', '-', 'DILEWATI: saldo nol atau terlalu kecil'];
                continue;
            }

            // Resolve ticker symbol and current price in USDT
            $ticker = null;
            $currentPriceUsdt = 0.0;

            foreach (['USDT', 'BIDR', 'IDR', 'IDRT'] as $quote) {
                $possibleTicker = $asset . $quote;
                if (isset($prices[$possibleTicker])) {
                    $ticker = $possibleTicker;
                    $rawPrice = $prices[$possibleTicker];
                    
                    // Convert quote price back to USDT equivalents if needed for comparisons
                    if ($quote === 'USDT') {
                        $currentPriceUsdt = $rawPrice;
                    } else {
                        $currentPriceUsdt = $rawPrice / $usdtIdr;
                    }
                    break;
                }
            }

            // If we couldn't resolve a price ticker, skip
            if (!$ticker || $currentPriceUsdt <= 0) {
                $this->warn("Could not resolve market price for asset: {$asset}");
                $laporan[] = [$asset, number_format($totalBalance, 4), '-', '-', '-',
                    'DILEWATI: harga tidak ketemu (dicari ' . $asset . 'USDT/BIDR/IDR/IDRT)'];
                continue;
            }

            // Fetch or create baseline price alert log
            $alertLog = TelegramPriceAlert::where('symbol', $ticker)->first();

            if (!$alertLog) {
                // Mode penjelasan tidak boleh mengubah apa pun, termasuk membuat patokan baru
                if (!$jelaskan) {
                    TelegramPriceAlert::create([
                        'symbol' => $ticker,
                        'last_alert_price' => $currentPriceUsdt,
                        'last_alert_time' => now(),
                        'last_seen_at' => now(),
                    ]);
                }
                $this->info("Initialized baseline price for {$ticker} at $" . $currentPriceUsdt);
                $laporan[] = [$asset, number_format($totalBalance, 4), $ticker,
                    number_format($currentPriceUsdt, 6), '-',
                    'DILEWATI: patokan baru dibuat, alert mulai pemeriksaan berikutnya'];
                continue;
            }

            // Aset yang sempat hilang dari portofolio: patokannya sudah tidak relevan.
            // Membandingkan harga sekarang dengan patokan berbulan lalu menghasilkan
            // "lonjakan" palsu yang sebenarnya pergerakan berbulan-bulan.
            $jeda = $alertLog->jamSejakTerlihat();
            $basi = $jeda === null || $jeda > self::JEDA_BASI_JAM;

            if ($basi) {
                if (!$jelaskan) {
                    $alertLog->update([
                        'last_alert_price' => $currentPriceUsdt,
                        'last_alert_time'  => now(),
                        'last_seen_at'     => now(),
                    ]);
                }

                $laporan[] = [$asset, number_format($totalBalance, 4), $ticker,
                    number_format($currentPriceUsdt, 6), '-',
                    sprintf('DISEGARKAN: tidak terlihat %s, patokan lama (%s) diabaikan',
                        $jeda === null ? 'sejak sebelum pencatatan' : round($jeda) . ' jam',
                        $alertLog->last_alert_price)];

                $this->info("Patokan {$ticker} disegarkan ke \$" . $currentPriceUsdt
                    . ' (sempat tidak terpantau).');
                continue;
            }

            // Aset terlihat hari ini — catat supaya jeda berikutnya terukur
            if (!$jelaskan) {
                $alertLog->update(['last_seen_at' => now()]);
            }

            $lastPrice = $alertLog->last_alert_price;
            $percentChange = (($currentPriceUsdt - $lastPrice) / $lastPrice) * 100.0;

            $laporan[] = [$asset, number_format($totalBalance, 4), $ticker,
                number_format($currentPriceUsdt, 6), sprintf('%+.2f%%', $percentChange),
                abs($percentChange) >= $threshold
                    ? 'ALERT DIKIRIM'
                    : sprintf('Tenang: gerak %.2f%% belum mencapai ambang %.2f%%', abs($percentChange), $threshold)];

            // Mode penjelasan tidak mengirim apa pun
            if ($jelaskan) {
                continue;
            }

            if (abs($percentChange) >= $threshold) {
                // Modal sisa posisi memakai rata-rata berjalan, sumber yang sama
                // dengan halaman Portofolio dan Laporan Bulanan. Rata-rata seluruh
                // pembelian keliru di sini: lot yang sudah habis terjual masih ikut
                // menyeret rata-rata posisi yang baru dibuka kembali.
                $basis = $this->costBasis->forAsset($asset, $prices);
                $avgBuyPriceUsdt = $basis['avg_price_usdt'];

                // Tanpa riwayat pembelian, anggap modal = harga pasar (PNL nol)
                if ($avgBuyPriceUsdt <= 0.0) {
                    $avgBuyPriceUsdt = $currentPriceUsdt;
                }

                // Calculate asset cost and unrealized PNL
                $assetCostUsdt = $totalBalance * $avgBuyPriceUsdt;
                $valuationUsdt = $totalBalance * $currentPriceUsdt;
                $unrealizedPnlUsdt = $valuationUsdt - $assetCostUsdt;
                $unrealizedPnlIdr = $unrealizedPnlUsdt * $usdtIdr;
                $pnlPercent = $avgBuyPriceUsdt > 0 ? (($currentPriceUsdt - $avgBuyPriceUsdt) / $avgBuyPriceUsdt) * 100.0 : 0.0;

                $direction = $percentChange > 0 ? 'PUMP 📈' : 'DUMP 📉';
                $sign = $percentChange > 0 ? '+' : '';
                
                // Formulate beautiful Markdown message
                $priceFormatted = $currentPriceUsdt >= 1.0 
                    ? '$' . number_format($currentPriceUsdt, 2) 
                    : '$' . number_format($currentPriceUsdt, 6);
                
                $lastPriceFormatted = $lastPrice >= 1.0 
                    ? '$' . number_format($lastPrice, 2) 
                    : '$' . number_format($lastPrice, 6);

                $avgBuyPriceFormatted = $avgBuyPriceUsdt >= 1.0 
                    ? '$' . number_format($avgBuyPriceUsdt, 2) 
                    : '$' . number_format($avgBuyPriceUsdt, 6);

                $pnlSign = $unrealizedPnlUsdt >= 0 ? '+' : '';
                $pnlColorEmoji = $unrealizedPnlUsdt >= 0 ? '🟢' : '🔴';
                
                $pnlPercentFormatted = number_format($pnlPercent, 2) . '%';
                $pnlValueFormatted = "Rp " . number_format($unrealizedPnlIdr, 0, ',', '.') . " (~$" . number_format($unrealizedPnlUsdt, 2) . ")";

                $valuationIdr = $valuationUsdt * $usdtIdr;

                $message = "🔔 *ALERT PERGERAKAN HARGA ({$direction})*\n\n"
                         . "Aset: *{$asset}* (Pair: `{$ticker}`)\n"
                         . "Harga Sebelumnya: *{$lastPriceFormatted}*\n"
                         . "Harga Sekarang: *{$priceFormatted}*\n"
                         . "Perubahan: *{$sign}" . number_format($percentChange, 2) . "%*\n\n"
                         . "📊 *Statistik Posisi Anda:*\n"
                         . "• Rata-rata Beli: *{$avgBuyPriceFormatted}*\n"
                         . "• Saldo Aset: *" . number_format($totalBalance, 4) . " {$asset}*\n"
                         . "• Estimasi Nilai: *Rp " . number_format($valuationIdr, 0, ',', '.') . "* (~$" . number_format($valuationUsdt, 2) . ")\n"
                         . "• PNL Belum Terealisasi: {$pnlColorEmoji} *{$pnlSign}{$pnlPercentFormatted}* ({$pnlSign}{$pnlValueFormatted})\n\n"
                         . "📱 _Silakan cek Jurnal Trading untuk rincian lengkap._";

                $this->info("Price Alert triggered for {$ticker}: {$sign}" . number_format($percentChange, 2) . "%");

                foreach ($recipients as $recipient) {
                    $this->sendTelegramMessage($botToken, $recipient->chat_id, $message);
                }

                // Update baseline to prevent repeat alerts
                $alertLog->update([
                    'last_alert_price' => $currentPriceUsdt,
                    'last_alert_time' => now(),
                    'last_seen_at' => now(),
                ]);
            }
        }

        if ($jelaskan) {
            $this->newLine();
            $this->line(sprintf('%-10s %16s %-12s %12s %9s  %s',
                'ASET', 'SALDO', 'PAIR', 'HARGA', 'GERAK', 'KEPUTUSAN'));
            $this->line(str_repeat('-', 110));

            foreach ($laporan as $r) {
                $this->line(sprintf('%-10s %16s %-12s %12s %9s  %s',
                    $r[0], $r[1], $r[2], $r[3], $r[4], $r[5]));
            }

            $this->newLine();
            $this->warn('Mode --explain: tidak ada notifikasi yang dikirim dan patokan harga tidak diperbarui.');
        }

        $this->info('Price alert check finished.');
        return 0;
    }

    /**
     * Helper to send cURL request to Telegram API.
     */
    private function sendTelegramMessage($token, $chatId, $message)
    {
        try {
            $response = Http::timeout(6)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);

            if (!$response->successful()) {
                Log::warning("Telegram sendMessage returned error for chat ID {$chatId}: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("Failed to send Telegram message to {$chatId}: " . $e->getMessage());
        }
    }
}
