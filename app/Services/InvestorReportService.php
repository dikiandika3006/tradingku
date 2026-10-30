<?php

namespace App\Services;

use App\Models\Investor;
use App\Models\InvestorReport;
use App\Models\InvestorTransaction;
use App\Support\SvgChart;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Menyusun isi Investor Report: perkembangan dana, modal, posisi & nilai tiap
 * investor, kinerja trading, benchmark, dan komposisi aset untuk satu periode.
 */
class InvestorReportService
{
    public const FUND_NAME = 'PELAGIC CAPITAL';

    protected const STABLES = ['USDT', 'BUSD', 'USDC', 'FDUSD', 'IDR', 'BIDR', 'IDRT'];

    /** Warna investor, sama dengan halaman Master Investor. */
    protected const INVESTOR_COLORS = ['#9d4edd', '#00b4d8', '#00c853', '#ffb703', '#f72585', '#90be6d', '#4361ee', '#fb8500'];

    protected ?array $chainCache = null;

    public function __construct(
        protected TokocryptoService $tokocryptoService,
        protected MonthlyReportService $monthlyReportService
    ) {
    }

    /**
     * @param bool $full false = hanya angka ringkas (untuk daftar), tanpa trading/grafik.
     */
    public function build(InvestorReport $report, bool $full = true): array
    {
        $transactions = $this->transactions();
        $modalAwal  = $this->modalPerInvestor($transactions, $report->period_start);
        $modalAkhir = $this->modalPerInvestor($transactions, $report->period_end);

        $totalAwal  = array_sum($modalAwal);
        $totalAkhir = array_sum($modalAkhir);

        $awal  = $this->kinerja($totalAwal, $report->start_portfolio_value);
        $akhir = $this->kinerja($totalAkhir, $report->end_portfolio_value);

        $chain = $this->chain()[$report->id];

        $palette = Investor::orderBy('code')->pluck('id')->values()
            ->mapWithKeys(fn ($id, $i) => [$id => self::INVESTOR_COLORS[$i % count(self::INVESTOR_COLORS)]]);

        $investors = Investor::orderBy('code')->get()
            ->filter(fn ($inv) => ($modalAwal[$inv->id] ?? 0) != 0 || ($modalAkhir[$inv->id] ?? 0) != 0)
            ->map(function ($inv) use ($modalAwal, $modalAkhir, $totalAkhir, $chain, $palette) {
                $modal = $modalAkhir[$inv->id] ?? 0.0;
                $nilai = $chain['end'][$inv->id] ?? $modal;

                return [
                    'name'      => $inv->name,
                    'color'     => $palette[$inv->id],
                    'awal'      => $modalAwal[$inv->id] ?? 0.0,
                    'akhir'     => $modal,
                    'perubahan' => $modal - ($modalAwal[$inv->id] ?? 0.0),
                    'porsi'     => $totalAkhir > 0 ? $modal / $totalAkhir * 100 : 0.0,
                    'nilai'     => $nilai,
                    'profit'    => $nilai - $modal,
                    'return'    => $modal > 0 ? ($nilai - $modal) / $modal * 100 : 0.0,
                ];
            })
            ->values();

        $allocation = collect($report->allocation ?? [])->sortByDesc('percent')->values()
            ->map(fn ($a, $i) => $a + ['color' => SvgChart::color($i)]);

        $data = [
            'fund_name'  => self::FUND_NAME,
            'report'     => $report,
            'awal'       => $awal,
            'akhir'      => $akhir,
            'tambahan'   => $totalAkhir - $totalAwal,
            'periode'    => ['gain' => $chain['gain'], 'return' => $chain['return']],
            'investors'  => $investors,
            'allocation' => $allocation,
            'top_asset'  => $allocation->first(),
            'porsi_kas'  => $allocation->whereIn('asset', self::STABLES)->sum('percent'),
        ];

        if (!$full) {
            return $data;
        }

        return $data + [
            'trading'      => $this->trading($report),
            'benchmark'    => $this->benchmark($report),
            'history'      => $this->history($report),
            'charts'       => [
                'allocation' => $allocation->isNotEmpty()
                    ? SvgChart::dataUri(SvgChart::pie($allocation->map(fn ($a) => ['value' => $a['percent'], 'color' => $a['color']])->all(), 170))
                    : null,
                'investors'  => $investors->isNotEmpty()
                    ? SvgChart::dataUri(SvgChart::pie($investors->map(fn ($i) => ['value' => $i['akhir'], 'color' => $i['color']])->all(), 170))
                    : null,
                'growth'     => $this->growthChart($report),
            ],
        ];
    }

    /** Tanggal default untuk report baru: lanjut dari akhir report terakhir sampai hari ini. */
    public function defaultPeriod(): array
    {
        $prev = InvestorReport::orderByDesc('period_end')->first();

        return [
            'period_start' => $prev?->period_end->format('Y-m-d') ?? now()->startOfMonth()->format('Y-m-d'),
            'period_end'   => now()->format('Y-m-d'),
        ];
    }

    /**
     * Isian otomatis untuk sebuah periode. Nilai awal memakai nilai akhir report
     * sebelumnya bila tanggalnya bersambung, supaya rangkaian report tidak putus.
     */
    public function suggest(Carbon $start, Carbon $end, ?int $exceptId = null): array
    {
        $prev = InvestorReport::whereDate('period_end', $start->format('Y-m-d'))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->orderByDesc('id')
            ->first();

        $awal = $prev
            ? ['ok' => true, 'message' => null, 'value_idr' => $prev->end_portfolio_value,
               'btc' => $prev->btc_end_price ?: $this->closePrice('BTCUSDT', $start)]
            : $this->valuationAt($start);
        $akhir = $this->valuationAt($end);

        $top = $akhir['allocation'][0]['asset'] ?? null;

        return [
            'start_portfolio_value' => $awal['value_idr'] !== null ? round($awal['value_idr']) : null,
            'start_source'          => $prev ? 'Nilai akhir report sebelumnya' : 'Dihitung dari jurnal trade',
            'start_message'         => $awal['message'],
            'end_portfolio_value'   => $akhir['value_idr'] !== null ? round($akhir['value_idr']) : null,
            'end_message'           => $akhir['message'],
            'usdt_idr_rate'         => $akhir['rate'],
            'btc_start_price'       => $awal['btc'],
            'btc_end_price'         => $akhir['btc'],
            'allocation_text'       => $this->allocationToText($akhir['allocation']),
            'portfolio_note'        => in_array($top, self::STABLES, true)
                ? "Porsi {$top} yang besar membuat portfolio saat ini lebih fleksibel menghadapi kondisi market yang masih cukup volatile."
                : '',
        ];
    }

    /**
     * Nilai portfolio (Rupiah), kurs, dan komposisi aset dari saldo Tokocrypto saat ini.
     *
     * @return array{value_idr: ?float, rate: ?float, allocation: array<int, array{asset: string, percent: float}>}
     */
    public function livePortfolio(): array
    {
        try {
            $portfolio = $this->tokocryptoService->getPortfolio();
        } catch (\Throwable $e) {
            return ['value_idr' => null, 'rate' => null, 'allocation' => []];
        }

        $totalUsdt = 0.0;
        $perAsset = [];
        foreach ($portfolio['assets'] ?? [] as $a) {
            $value = (float)($a['value_usdt'] ?? 0);
            if ($value <= 0) {
                continue;
            }
            $perAsset[$a['asset']] = ($perAsset[$a['asset']] ?? 0) + $value;
            $totalUsdt += $value;
        }

        arsort($perAsset);
        $allocation = [];
        foreach ($perAsset as $asset => $value) {
            $percent = round($value / $totalUsdt * 100, 2);
            if ($percent >= 0.01) {
                $allocation[] = ['asset' => $asset, 'percent' => $percent];
            }
        }

        $rate = (float)($portfolio['usdt_idr_rate'] ?? 16000.0);

        return [
            'value_idr'  => $totalUsdt * $rate,
            'rate'       => $rate,
            'allocation' => $allocation,
        ];
    }

    /**
     * Nilai portfolio pada akhir hari $date, direkonstruksi dari jurnal trade
     * (DEPOSIT/WITHDRAW/BUY/SELL) dan harga penutupan harian hari itu.
     * Untuk hari ini dipakai saldo & harga terkini.
     *
     * @return array{ok: bool, message: ?string, value_idr: ?float, rate: ?float, btc: ?float,
     *               allocation: array<int, array{asset: string, percent: float}>, missing: array<int, string>}
     */
    public function valuationAt(Carbon $date): array
    {
        if ($date->isToday() || $date->isFuture()) {
            $live = $this->livePortfolio();

            return [
                'ok'         => $live['value_idr'] !== null,
                'message'    => $live['value_idr'] === null ? 'Saldo portfolio terkini tidak bisa diambil.' : null,
                'value_idr'  => $live['value_idr'],
                'rate'       => $live['rate'],
                'btc'        => $this->closePrice('BTCUSDT', now()),
                'allocation' => $live['allocation'],
                'missing'    => [],
            ];
        }

        $holdings = array_filter(
            $this->tokocryptoService->holdingsFromJournal($date->copy()->endOfDay()),
            fn ($amount) => $amount > 0.00001
        );

        $fail = fn ($message) => ['ok' => false, 'message' => $message, 'value_idr' => null, 'rate' => null,
            'btc' => $this->closePrice('BTCUSDT', $date), 'allocation' => [], 'missing' => []];

        if (empty($holdings)) {
            return $fail('Jurnal trade belum mencatat saldo apa pun pada tanggal ini, isi nilainya manual.');
        }

        $rate = $this->closePrice('USDTIDR', $date);
        if (!$rate) {
            return $fail('Kurs USDT/IDR pada tanggal ini tidak bisa diambil, coba lagi atau isi manual.');
        }

        $perAsset = [];
        $missing = [];
        foreach ($holdings as $asset => $amount) {
            $price = match (true) {
                in_array($asset, ['IDR', 'BIDR', 'IDRT'], true)       => 1 / $rate,
                in_array($asset, self::STABLES, true)                 => 1.0,
                default                                               => $this->closePrice($asset . 'USDT', $date),
            };

            if ($price === null) {
                $missing[] = $asset;
                continue;
            }
            $perAsset[$asset] = $amount * $price;
        }

        $totalUsdt = array_sum($perAsset);
        arsort($perAsset);

        $allocation = [];
        foreach ($perAsset as $asset => $value) {
            $percent = $totalUsdt > 0 ? round($value / $totalUsdt * 100, 2) : 0;
            if ($percent >= 0.01) {
                $allocation[] = ['asset' => $asset, 'percent' => $percent];
            }
        }

        return [
            'ok'         => true,
            'message'    => $missing ? 'Harga aset berikut tidak ditemukan dan tidak ikut dihitung: ' . implode(', ', $missing) : null,
            'value_idr'  => $totalUsdt * $rate,
            'rate'       => $rate,
            'btc'        => $this->closePrice('BTCUSDT', $date),
            'allocation' => $allocation,
            'missing'    => $missing,
        ];
    }

    /**
     * Harga penutupan candle harian pada tanggal tersebut (hari ini = harga terkini).
     * Harga hari yang sudah lewat tidak berubah, jadi disimpan permanen di cache.
     */
    public function closePrice(string $symbol, Carbon $date): ?float
    {
        $fetch = function () use ($symbol, $date) {
            $params = [
                'symbol'    => $symbol,
                'interval'  => '1d',
                'startTime' => $date->copy()->startOfDay()->getTimestampMs(),
                'limit'     => 1,
            ];

            // Binance diblokir dari Indonesia, jadi Tokocrypto didahulukan.
            foreach (['https://www.tokocrypto.site/api/v3/klines', 'https://api.binance.com/api/v3/klines'] as $url) {
                try {
                    $res = Http::timeout(5)->get($url, $params);
                    $close = $res->successful() ? ($res->json()[0][4] ?? null) : null;
                    if ($close) {
                        return (float)$close;
                    }
                } catch (\Throwable $e) {
                    // coba sumber berikutnya
                }
            }

            return null;
        };

        if ($date->isToday() || $date->isFuture()) {
            return $fetch();
        }

        // Cache tidak menyimpan null, jadi kegagalan sementara akan dicoba lagi.
        return Cache::rememberForever("investor-report:close:{$symbol}:{$date->format('Y-m-d')}", $fetch);
    }

    /** Format teks "ASET = persen" per baris, untuk diedit di form. */
    public function allocationToText(array $allocation): string
    {
        return collect($allocation)
            ->map(fn ($a) => $a['asset'] . ' = ' . rtrim(rtrim(number_format($a['percent'], 2, '.', ''), '0'), '.'))
            ->implode("\n");
    }

    /** Kebalikan allocationToText(); baris yang tidak bisa dibaca dilewati. */
    public function parseAllocation(?string $text): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', (string)$text) as $line) {
            if (preg_match('/^\s*([A-Za-z0-9]+)\s*[=:\s]\s*([\d.,]+)\s*%?\s*$/', $line, $m)) {
                $rows[] = [
                    'asset'   => strtoupper($m[1]),
                    'percent' => (float)str_replace(',', '.', $m[2]),
                ];
            }
        }

        return $rows;
    }

    /**
     * Nilai tiap investor di awal & akhir setiap report, dirantai dari report pertama.
     *
     * Hasil periode (nilai akhir − nilai awal − setoran) dibagi menurut metode
     * Modified Dietz: modal awal ikut penuh, setoran di tengah periode ikut
     * sebanding lamanya dana berada di portfolio. Jadi investor yang baru top up
     * tidak ikut menikmati (atau menanggung) hasil sebelum dananya masuk.
     *
     * @return array<int, array{end: array<int, float>, gain: float, return: float}>
     */
    protected function chain(): array
    {
        if ($this->chainCache !== null) {
            return $this->chainCache;
        }

        $transactions = $this->transactions();
        $result = [];
        $prev = null;
        $prevEnd = [];

        foreach (InvestorReport::orderBy('period_end')->orderBy('id')->get() as $r) {
            $start = $r->period_start;
            $end = $r->period_end;

            if ($prev === null) {
                // Report pertama: hasil sebelum periode dibagi menurut modal saat itu.
                $values = $this->modalPerInvestor($transactions, $start);
                $since = $start;
            } else {
                $values = $prevEnd;
                foreach ($transactions as $tx) {
                    if ($tx->date->gt($prev->period_end) && $tx->date->lte($start)) {
                        $values[$tx->investor_id] = ($values[$tx->investor_id] ?? 0.0) + $tx->signedAmount();
                    }
                }
                $since = $prev->period_end->gt($start) ? $prev->period_end : $start;
            }

            // Selaraskan dengan nilai awal yang tercatat; selisih (hasil di luar
            // periode report) dibagi proporsional.
            $sum = array_sum($values);
            if ($sum > 0) {
                foreach ($values as $id => $v) {
                    $values[$id] = $v / $sum * $r->start_portfolio_value;
                }
            }

            $days = max(1, $start->diffInDays($end));
            $setoran = [];
            $basis = $values;
            foreach ($transactions as $tx) {
                if ($tx->date->gt($since) && $tx->date->lte($end)) {
                    $amount = $tx->signedAmount();
                    $setoran[$tx->investor_id] = ($setoran[$tx->investor_id] ?? 0.0) + $amount;
                    $basis[$tx->investor_id] = ($basis[$tx->investor_id] ?? 0.0)
                        + $amount * $tx->date->diffInDays($end) / $days;
                }
            }

            $gain = $r->end_portfolio_value - $r->start_portfolio_value - array_sum($setoran);
            $totalBasis = array_sum($basis);

            $endValues = [];
            foreach (array_keys($basis) as $id) {
                $share = $totalBasis > 0 ? $basis[$id] / $totalBasis : 0.0;
                $endValues[$id] = ($values[$id] ?? 0.0) + ($setoran[$id] ?? 0.0) + $gain * $share;
            }

            $result[$r->id] = [
                'end'    => $endValues,
                'gain'   => $gain,
                'return' => $totalBasis > 0 ? $gain / $totalBasis * 100 : 0.0,
            ];

            $prev = $r;
            $prevEnd = $endValues;
        }

        return $this->chainCache = $result;
    }

    /** Statistik trade yang ditutup (SELL) di dalam periode: (awal, akhir]. */
    protected function trading(InvestorReport $report): array
    {
        try {
            $trades = $this->monthlyReportService->realizedTradesBetween(
                $report->period_start->copy()->addDay(),
                $report->period_end
            );
        } catch (\Throwable $e) {
            $trades = [];
        }

        $rate = $report->usdt_idr_rate ?: 16000.0;
        $wins = count(array_filter($trades, fn ($t) => $t['pnl_usdt'] > 0));
        $realized = array_sum(array_column($trades, 'pnl_usdt'));

        $perAsset = [];
        foreach ($trades as $t) {
            $perAsset[$t['asset']] = ($perAsset[$t['asset']] ?? 0.0) + $t['pnl_usdt'];
        }
        arsort($perAsset);

        $toIdr = fn ($rows) => collect($rows)->map(fn ($v, $a) => ['asset' => $a, 'pnl_idr' => $v * $rate])->values();

        return [
            'count'        => count($trades),
            'wins'         => $wins,
            'losses'       => count($trades) - $wins,
            'win_rate'     => count($trades) > 0 ? $wins / count($trades) * 100 : 0.0,
            'realized_idr' => $realized * $rate,
            'best'         => $toIdr(array_slice(array_filter($perAsset, fn ($v) => $v > 0), 0, 3, true)),
            'worst'        => $toIdr(array_slice(array_reverse(array_filter($perAsset, fn ($v) => $v < 0), true), 0, 3, true)),
        ];
    }

    protected function benchmark(InvestorReport $report): ?array
    {
        if (!$report->btc_start_price || !$report->btc_end_price) {
            return null;
        }

        return [
            'start'  => $report->btc_start_price,
            'end'    => $report->btc_end_price,
            'return' => ($report->btc_end_price - $report->btc_start_price) / $report->btc_start_price * 100,
        ];
    }

    /** Return setiap report sampai report ini, terlama di atas. */
    protected function history(InvestorReport $report)
    {
        $chain = $this->chain();
        $transactions = $this->transactions();

        return InvestorReport::where('period_end', '<=', $report->period_end)
            ->orderBy('period_end')->orderBy('id')->get()
            ->map(function ($r) use ($chain, $transactions) {
                $modal = array_sum($this->modalPerInvestor($transactions, $r->period_end));

                return [
                    'report'     => $r,
                    'modal'      => $modal,
                    'nilai'      => $r->end_portfolio_value,
                    'return'     => $chain[$r->id]['return'],
                    'cumulative' => $modal > 0 ? ($r->end_portfolio_value - $modal) / $modal * 100 : 0.0,
                ];
            });
    }

    /** Grafik nilai portfolio vs total modal: awal report pertama lalu akhir tiap report. */
    protected function growthChart(InvestorReport $report): string
    {
        $transactions = $this->transactions();
        $reports = InvestorReport::where('period_end', '<=', $report->period_end)
            ->orderBy('period_end')->orderBy('id')->get();

        $first = $reports->first();
        $points = [[$first->period_start, $first->start_portfolio_value]];
        foreach ($reports as $r) {
            $points[] = [$r->period_end, $r->end_portfolio_value];
        }

        $labels = array_map(fn ($p) => $p[0]->locale('id')->translatedFormat('d M y'), $points);
        $nilai = array_map(fn ($p) => $p[1], $points);
        $modal = array_map(fn ($p) => array_sum($this->modalPerInvestor($transactions, $p[0])), $points);

        $svg = SvgChart::line($labels, [
            ['values' => $modal, 'color' => '#9ca3af', 'dashed' => true],
            ['values' => $nilai, 'color' => '#2563eb'],
        ], fn ($v) => 'Rp' . rtrim(rtrim(number_format($v / 1e6, 1, ',', '.'), '0'), ',') . ' jt');

        return SvgChart::dataUri($svg);
    }

    protected function transactions()
    {
        return InvestorTransaction::orderBy('date')->orderBy('id')->get();
    }

    /** Saldo modal tiap investor per akhir hari $date. */
    protected function modalPerInvestor($transactions, Carbon $date): array
    {
        $saldo = [];
        foreach ($transactions as $tx) {
            if ($tx->date->gt($date)) {
                break;
            }
            $saldo[$tx->investor_id] = ($saldo[$tx->investor_id] ?? 0.0) + $tx->signedAmount();
        }

        return $saldo;
    }

    protected function kinerja(float $modal, float $nilai): array
    {
        $profit = $nilai - $modal;

        return [
            'modal'  => $modal,
            'nilai'  => $nilai,
            'profit' => $profit,
            'return' => $modal > 0 ? $profit / $modal * 100 : 0.0,
        ];
    }
}
