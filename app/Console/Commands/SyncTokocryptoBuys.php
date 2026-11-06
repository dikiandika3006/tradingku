<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Trade;
use App\Services\TechnicalAnalysisService;
use App\Services\TokocryptoService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Auto-post pembelian di Tokocrypto ke jurnal transaksi.
 *
 * - Hanya sisi beli, mulai MULAI (atau --since).
 * - Satu order = satu baris jurnal, dijumlah dari seluruh fill-nya.
 * - Order yang masih terbuka (terisi sebagian) ditunda sampai selesai.
 * - Fee dalam koin yang dibeli mengurangi jumlah; fee dalam quote menambah
 *   modal. Jadi jumlah di jurnal = saldo yang benar-benar diterima, dan
 *   harga = modal / jumlah.
 * - Stablecoin yang dibeli pakai rupiah (USDT_IDR) dicatat DEPOSIT, sesuai
 *   kebiasaan jurnal: itu suntikan modal, bukan pertumbuhan.
 * - Order yang sudah dicatat manual tidak diduplikasi: baris manualnya
 *   ditandai dengan nomor order.
 */
class SyncTokocryptoBuys extends Command
{
    protected $signature = 'tokocrypto:sync-buys
        {--since= : Mulai dari waktu ini (WIB), mis. "2026-11-06 00:00". Bawaan: sejak sinkron terakhir}
        {--dry-run : Tampilkan saja, tidak menulis ke jurnal}';

    protected $description = 'Catat otomatis pembelian di Tokocrypto ke jurnal trading';

    /** Auto-post berlaku untuk pembelian mulai waktu ini (WIB). */
    public const MULAI = '2026-11-06 00:00:00';

    /**
     * Jam di jurnal adalah jam WIB apa adanya (form mengisinya dari jam browser),
     * sedangkan zona aplikasi UTC. Semua waktu di sini memakai WIB.
     */
    public const ZONA_JURNAL = 'Asia/Jakarta';

    public const SETTING_TERAKHIR = 'tokocrypto_autopost_terakhir_ms';

    /** Ambil ulang sedikit ke belakang dari sinkron terakhir; duplikat tetap tertolak. */
    protected const TUMPANG_TINDIH_MS = 2 * 3600 * 1000;

    protected const STABLECOIN = ['USDT', 'USDC', 'BUSD', 'FDUSD', 'TUSD', 'DAI'];
    protected const FIAT = ['IDR', 'BIDR', 'IDRT'];

    /** Rentang waktu & toleransi jumlah untuk mengenali catatan manual. */
    protected const COCOK_SEBELUM_JAM = 24;
    protected const COCOK_SESUDAH_JAM = 2;
    protected const COCOK_TOLERANSI = 0.005;

    public function __construct(
        protected TokocryptoService $tokocrypto,
        protected TechnicalAnalysisService $teknikal
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!$this->tokocrypto->hasCredentials()) {
            $this->warn('API key Tokocrypto belum terpasang, auto-post dilewati.');
            return self::SUCCESS;
        }

        $zona = self::ZONA_JURNAL;
        $mulaiMs = Carbon::parse(self::MULAI, 'Asia/Jakarta')->getTimestampMs();

        if ($this->option('since')) {
            $dariMs = max($mulaiMs, Carbon::parse($this->option('since'), 'Asia/Jakarta')->getTimestampMs());
        } else {
            $terakhir = (int) Setting::where('key', self::SETTING_TERAKHIR)->value('value');
            $dariMs = max($mulaiMs, $terakhir - self::TUMPANG_TINDIH_MS);
        }
        $sekarangMs = Carbon::now()->getTimestampMs();

        $fills = $this->tokocrypto->fetchMyTrades($dariMs, $sekarangMs);
        $terbuka = $this->tokocrypto->fetchOpenOrders();

        if ($fills === null || $terbuka === null) {
            $this->error('Gagal mengambil data dari Tokocrypto. Tidak ada yang dicatat; dicoba lagi di jadwal berikutnya.');
            return self::FAILURE;
        }

        $idTerbuka = array_map('strval', array_column($terbuka, 'orderId'));
        $orders = self::susunOrder($fills);

        $baris = [];
        $batasAman = $sekarangMs; // sinkron berikutnya tidak boleh melewati fill order yang ditunda

        foreach ($orders as $o) {
            $label = "{$o['symbol']} #{$o['order_id']}";

            if (in_array($o['order_id'], $idTerbuka, true)) {
                $batasAman = min($batasAman, $o['fill_pertama_ms']);
                $baris[] = [$label, $o['type'], $o['amount'], 'ditunda: order masih terbuka'];
                continue;
            }

            if (Trade::where('exchange_order_id', $o['order_id'])->exists()) {
                $baris[] = [$label, $o['type'], $o['amount'], 'sudah ada di jurnal'];
                continue;
            }

            $waktu = Carbon::createFromTimestampMs($o['waktu_ms'], $zona);
            $manual = $this->cariCatatanManual($o, $waktu);

            if ($manual) {
                if (!$this->option('dry-run')) {
                    $manual->update(['exchange_order_id' => $o['order_id']]);
                }
                $baris[] = [$label, $o['type'], $o['amount'], "dicatat manual (#{$manual->id}), ditandai"];
                continue;
            }

            if (!$this->option('dry-run')) {
                Trade::create($this->dataJurnal($o, $waktu));
            }
            $baris[] = [$label, $o['type'], $o['amount'], $this->option('dry-run') ? 'akan dicatat' : 'DICATAT'];
        }

        if ($baris) {
            $this->table(['Order', 'Tipe', 'Jumlah', 'Hasil'], $baris);
        } else {
            $this->info('Tidak ada pembelian baru sejak ' . Carbon::createFromTimestampMs($dariMs)->setTimezone($zona)->format('d M Y H:i') . '.');
        }

        if (!$this->option('dry-run') && !$this->option('since')) {
            Setting::updateOrCreate(['key' => self::SETTING_TERAKHIR], ['value' => (string) $batasAman]);
        }

        return self::SUCCESS;
    }

    /**
     * Kelompokkan fill beli per order dan hitung jumlah bersih, modal, harga.
     *
     * @param  array<int, array>  $fills  baris mentah /open/v1/orders/trades
     * @return array<int, array>
     */
    public static function susunOrder(array $fills): array
    {
        $grup = [];

        foreach ($fills as $f) {
            if (!filter_var($f['isBuyer'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $id = (string) ($f['orderId'] ?? '');
            if ($id === '') {
                continue;
            }

            [$base, $quote] = array_pad(explode('_', strtoupper((string) ($f['symbol'] ?? ''))), 2, '');
            if ($base === '' || $quote === '') {
                continue;
            }

            $g = &$grup[$id];
            $g['order_id'] = $id;
            $g['base'] = $base;
            $g['quote'] = $quote;
            $g['qty'] = ($g['qty'] ?? 0) + (float) ($f['qty'] ?? 0);
            $g['quote_qty'] = ($g['quote_qty'] ?? 0) + (float) ($f['quoteQty'] ?? 0);
            $g['fee'][strtoupper((string) ($f['commissionAsset'] ?? ''))] =
                ($g['fee'][strtoupper((string) ($f['commissionAsset'] ?? ''))] ?? 0) + (float) ($f['commission'] ?? 0);
            $t = (int) ($f['time'] ?? 0);
            $g['waktu_ms'] = max($g['waktu_ms'] ?? 0, $t);
            $g['fill_pertama_ms'] = min($g['fill_pertama_ms'] ?? PHP_INT_MAX, $t);
            unset($g);
        }

        $hasil = [];
        foreach ($grup as $g) {
            $feeBase = $g['fee'][$g['base']] ?? 0.0;
            $feeQuote = $g['fee'][$g['quote']] ?? 0.0;

            $amount = round($g['qty'] - $feeBase, 8);
            $modal = $g['quote_qty'] + $feeQuote;
            if ($amount <= 0) {
                continue;
            }

            $fiatKeStable = in_array($g['base'], self::STABLECOIN, true) && in_array($g['quote'], self::FIAT, true);

            $feeTeks = [];
            foreach ($g['fee'] as $aset => $nilai) {
                if ($nilai > 0 && $aset !== '') {
                    $feeTeks[] = rtrim(rtrim(number_format($nilai, 8, '.', ''), '0'), '.') . " {$aset}";
                }
            }

            $hasil[] = [
                'order_id' => $g['order_id'],
                'symbol' => $g['base'] . $g['quote'],
                'base' => $g['base'],
                'quote' => $g['quote'],
                'type' => $fiatKeStable ? 'DEPOSIT' : 'BUY',
                'qty_kotor' => round($g['qty'], 8),
                'amount' => $amount,
                'total' => round($modal, 8),
                'price' => round($modal / $amount, 8),
                'harga_fill' => $g['qty'] > 0 ? $g['quote_qty'] / $g['qty'] : 0.0,
                'fee_teks' => $feeTeks ? implode(' + ', $feeTeks) : '0',
                'waktu_ms' => $g['waktu_ms'],
                'fill_pertama_ms' => $g['fill_pertama_ms'],
            ];
        }

        usort($hasil, fn($a, $b) => $a['waktu_ms'] <=> $b['waktu_ms']);

        return $hasil;
    }

    /**
     * Baris jurnal manual yang kemungkinan besar order yang sama: simbol & tipe
     * sama, jumlah (kotor atau bersih) selisih maks. 0,5%, dan waktu di sekitar
     * fill. Dipilih yang waktunya paling dekat.
     */
    protected function cariCatatanManual(array $o, Carbon $waktu): ?Trade
    {
        $kandidat = Trade::whereNull('exchange_order_id')
            ->where('symbol', $o['symbol'])
            ->where('type', $o['type'])
            ->whereBetween('trade_time', [
                $waktu->copy()->subHours(self::COCOK_SEBELUM_JAM)->format('Y-m-d H:i:s'),
                $waktu->copy()->addHours(self::COCOK_SESUDAH_JAM)->format('Y-m-d H:i:s'),
            ])
            ->get()
            ->filter(function (Trade $t) use ($o) {
                $jumlah = (float) $t->amount;
                foreach ([$o['qty_kotor'], $o['amount']] as $acuan) {
                    if ($acuan > 0 && abs($jumlah - $acuan) / $acuan <= self::COCOK_TOLERANSI) {
                        return true;
                    }
                }
                return false;
            });

        return $kandidat
            ->sortBy(fn(Trade $t) => abs(Carbon::parse($t->getRawOriginal('trade_time'), self::ZONA_JURNAL)->diffInSeconds($waktu)))
            ->first();
    }

    protected function dataJurnal(array $o, Carbon $waktu): array
    {
        $data = [
            'symbol' => $o['symbol'],
            'type' => $o['type'],
            'price' => $o['price'],
            'amount' => $o['amount'],
            'total' => $o['total'],
            'trade_time' => $waktu->format('Y-m-d H:i:s'), // jam WIB apa adanya, seperti input form
            'divergence' => 'None',
            'exchange_order_id' => $o['order_id'],
            'notes' => sprintf(
                'Auto dari Tokocrypto · order #%s · harga fill %s %s · fee %s',
                $o['order_id'],
                rtrim(rtrim(number_format($o['harga_fill'], 8, '.', ''), '0'), '.'),
                $o['quote'],
                $o['fee_teks']
            ),
        ];

        // Indikator saat dicatat, seperti tombol Auto-Fill di form. Tidak wajib.
        if ($o['type'] === 'BUY') {
            try {
                $m = $this->teknikal->getMetrics($o['symbol']);
                if (is_array($m) && isset($m['stoch_k'], $m['stoch_d'])) {
                    $data['stoch_rsi_k'] = round((float) $m['stoch_k'], 2);
                    $data['stoch_rsi_d'] = round((float) $m['stoch_d'], 2);
                    $data['divergence'] = $m['divergence'] ?: 'None';
                }
            } catch (\Throwable $e) {
                // indikator kosong lebih baik daripada auto-post gagal
            }
        }

        return $data;
    }
}
