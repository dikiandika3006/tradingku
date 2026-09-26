<?php

namespace App\Console\Commands;

use App\Models\Trade;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menyeimbangkan saldo mata uang fiat di jurnal transaksi.
 *
 * Rupiah yang disuntikkan dari luar sering tidak dijurnal masuk, sedangkan setiap
 * pembelian berkuota IDR mendebit saldonya. Akibatnya saldo fiat menjadi negatif —
 * mencatat utang rupiah yang sebenarnya tidak pernah ada.
 *
 * Perintah ini menelusuri jurnal secara kronologis, mencari titik-titik saat saldo
 * fiat akan menjadi negatif, lalu mengusulkan transaksi DEPOSIT tepat sebelum titik
 * itu sebesar kekurangannya. Hasilnya saldo tidak pernah minus di sepanjang riwayat,
 * bukan sekadar ditambal di akhir.
 *
 * Bersifat idempoten: setelah diterapkan, menjalankannya lagi tidak menemukan
 * kekurangan apa pun.
 */
class BalanceFiatLedger extends Command
{
    protected $signature = 'portfolio:balance-fiat
                            {--currency= : Batasi ke satu mata uang (mis. IDR). Default: semua fiat}
                            {--apply : Benar-benar membuat transaksi DEPOSIT. Tanpa ini hanya menampilkan usulan}';

    protected $description = 'Seimbangkan saldo fiat yang negatif dengan transaksi DEPOSIT penyeimbang';

    /** Mata uang yang diperlakukan sebagai fiat/settlement. */
    protected const FIAT = ['IDR', 'BIDR', 'IDRT'];

    /** Penanda agar transaksi hasil perintah ini mudah dikenali. */
    protected const NOTE = 'Setoran penyeimbang (portfolio:balance-fiat)';

    /** Toleransi pembulatan; di bawah ini dianggap nol. */
    protected const EPSILON = 0.01;

    public function handle(): int
    {
        $currencies = $this->option('currency')
            ? [strtoupper(trim($this->option('currency')))]
            : self::FIAT;

        foreach ($currencies as $fiat) {
            if (!in_array($fiat, self::FIAT, true)) {
                $this->error("'{$fiat}' bukan mata uang fiat yang dikenali. Pilihan: " . implode(', ', self::FIAT));
                return self::FAILURE;
            }
        }

        $adaKekurangan = false;

        foreach ($currencies as $fiat) {
            $hasil = $this->analyse($fiat);

            if (empty($hasil['shortfalls'])) {
                $this->line(sprintf('%-5s saldo %s — tidak perlu penyeimbang.',
                    $fiat, $this->rp($hasil['current_balance'])));
                continue;
            }

            $adaKekurangan = true;
            $total = array_sum(array_column($hasil['shortfalls'], 'amount'));

            $this->newLine();
            $this->info("{$fiat} — ditemukan " . count($hasil['shortfalls']) . ' kekurangan:');

            foreach ($hasil['shortfalls'] as $s) {
                $this->line(sprintf('  %s  perlu setor %-18s  (sebelum: %s %s)',
                    $s['at']->format('Y-m-d H:i:s'),
                    $this->rp($s['amount']),
                    $s['trigger_type'],
                    $s['trigger_symbol']));
            }

            $this->line(sprintf('  Total kekurangan   : %s', $this->rp($total)));
            $this->line(sprintf('  Saldo sekarang     : %s', $this->rp($hasil['current_balance'])));
            $this->line(sprintf('  Saldo setelah setor: %s', $this->rp($hasil['balanced_balance'])));

            // Sisa positif berarti jurnal mencatat masih ada rupiah mengendap.
            // Kalau kenyataannya tidak, ada pembelian atau penarikan yang belum dicatat.
            if ($hasil['balanced_balance'] > self::EPSILON) {
                $this->line(sprintf(
                    '  Catatan: menurut jurnal masih ada %s mengendap. Kalau rupiah itu',
                    $this->rp($hasil['balanced_balance'])
                ));
                $this->line('  sebenarnya sudah terpakai, berarti ada transaksi yang belum dijurnal.');
            }

            if ($this->option('apply')) {
                $this->apply($fiat, $hasil['shortfalls']);
            }
        }

        if (!$adaKekurangan) {
            $this->newLine();
            $this->info('Semua saldo fiat sudah seimbang.');
            return self::SUCCESS;
        }

        if (!$this->option('apply')) {
            $this->newLine();
            $this->warn('Ini baru usulan. Jalankan ulang dengan --apply untuk benar-benar membuat transaksinya.');
        }

        return self::SUCCESS;
    }

    /**
     * Telusuri jurnal kronologis, catat setiap titik saat saldo fiat akan minus.
     *
     * Dua saldo dilacak sekaligus: apa adanya menurut jurnal sekarang, dan
     * seandainya setoran penyeimbang sudah dibuat.
     *
     * @return array{shortfalls: array<int, array>, current_balance: float, balanced_balance: float}
     */
    protected function analyse(string $fiat): array
    {
        $trades = Trade::orderBy('trade_time', 'asc')->orderBy('id', 'asc')->get();

        $current    = 0.0;   // saldo apa adanya, bisa minus
        $balanced   = 0.0;   // saldo seandainya sudah diseimbangkan
        $shortfalls = [];

        foreach ($trades as $trade) {
            $delta = $this->deltaFor($trade, $fiat);

            if (abs($delta) < 1e-9) {
                continue;
            }

            $current += $delta;

            // Saldo akan minus -> berarti ada setoran yang belum dijurnal
            if ($balanced + $delta < -self::EPSILON) {
                $kurang = abs($balanced + $delta);

                $shortfalls[] = [
                    'amount'         => $kurang,
                    'at'             => Carbon::parse($trade->trade_time)->subSecond(),
                    'trigger_type'   => strtoupper(trim($trade->type)),
                    'trigger_symbol' => strtoupper(trim($trade->symbol)),
                ];

                $balanced += $kurang;
            }

            $balanced += $delta;
        }

        return [
            'shortfalls'       => $shortfalls,
            'current_balance'  => $current,
            'balanced_balance' => $balanced,
        ];
    }

    /**
     * Berapa transaksi ini menggerakkan saldo fiat tersebut.
     *
     * Mengikuti cara saldo direkonstruksi di TokocryptoService: pembelian mendebit
     * sisi quote, penjualan mengkreditkannya, dan setoran/penarikan menyentuh aset
     * dasarnya saja.
     */
    protected function deltaFor(Trade $trade, string $fiat): float
    {
        $type  = strtoupper(trim($trade->type));
        $total = (float)$trade->total;

        if ($total <= 0) {
            $total = (float)$trade->price * (float)$trade->amount;
        }

        [$base, $quote] = Trade::splitSymbol($trade->symbol);

        if ($type === 'DEPOSIT') {
            return $base === $fiat ? (float)$trade->amount : 0.0;
        }

        if ($type === 'WITHDRAW') {
            return $base === $fiat ? -(float)$trade->amount : 0.0;
        }

        if ($quote !== $fiat) {
            return 0.0;
        }

        if ($type === 'BUY') {
            return -$total;
        }

        if ($type === 'SELL') {
            return $total;
        }

        return 0.0;
    }

    /**
     * Buat transaksi DEPOSIT penyeimbang.
     *
     * @param array<int, array> $shortfalls
     */
    protected function apply(string $fiat, array $shortfalls): void
    {
        DB::transaction(function () use ($fiat, $shortfalls) {
            foreach ($shortfalls as $s) {
                Trade::create([
                    'symbol'     => $fiat,
                    'type'       => 'DEPOSIT',
                    'price'      => 1.0,
                    'amount'     => $s['amount'],
                    'total'      => $s['amount'],
                    'trade_time' => $s['at'],
                    'notes'      => self::NOTE,
                ]);
            }
        });

        $total = array_sum(array_column($shortfalls, 'amount'));

        $this->info(sprintf('  -> %d transaksi DEPOSIT %s dibuat, total %s.',
            count($shortfalls), $fiat, $this->rp($total)));

        Log::info("Fiat ledger balanced for {$fiat}", [
            'entries' => count($shortfalls),
            'total'   => $total,
        ]);
    }

    protected function rp(float $value): string
    {
        return 'Rp ' . number_format($value, 2, ',', '.');
    }
}
