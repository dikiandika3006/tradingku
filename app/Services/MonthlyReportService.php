<?php

namespace App\Services;

use App\Models\MonthlyPortfolioSnapshot;
use App\Models\Trade;
use Carbon\Carbon;

/**
 * Monthly Portfolio Snapshot Engine.
 *
 * Membangun laporan bulanan dengan mekanisme cut-off:
 * - Bulan yang sudah difinalisasi (is_finalized = true) diambil apa adanya dari
 *   tabel monthly_portfolio_snapshots dan TIDAK PERNAH dihitung ulang.
 * - Bulan berjalan dihitung live dari saldo & harga terkini.
 * - Bulan lampau yang belum punya snapshot direkonstruksi mundur dari anchor
 *   terdekat (snapshot berikutnya, atau saldo live bila belum ada snapshot sama sekali).
 */
class MonthlyReportService
{
    protected const FIAT_STABLES = ['USDT', 'BIDR', 'IDRT', 'BUSD', 'USDC', 'IDR'];

    protected $tokocryptoService;

    protected $costBasisService;

    public function __construct(
        TokocryptoService $tokocryptoService,
        CostBasisService $costBasisService
    ) {
        $this->tokocryptoService = $tokocryptoService;
        $this->costBasisService  = $costBasisService;
    }

    /**
     * Build the full monthly dataset (chronological order).
     *
     * @param bool $useSnapshots false = abaikan snapshot, hitung semuanya live (untuk backfill awal).
     */
    public function build(bool $useSnapshots = true): array
    {
        $prices    = $this->tokocryptoService->getAllPrices();
        $portfolio = $this->tokocryptoService->getPortfolio();
        $usdtIdr   = $portfolio['usdt_idr_rate'] ?? 16000.0;

        $floating       = $this->calculateFloatingPnl($portfolio, $prices);
        $realizedTrades = $this->calculateRealizedTrades($prices);
        $monthlyGroups  = $this->groupByMonth($realizedTrades['trades']);

        // Saldo portofolio live (nilai riil hari ini)
        $liveValuationUsdt = 0.0;
        foreach ($portfolio['assets'] ?? [] as $assetData) {
            $liveValuationUsdt += (float)($assetData['value_usdt'] ?? 0);
        }

        $currentYm = date('Y-m');
        $snapshots = $useSnapshots
            ? MonthlyPortfolioSnapshot::where('is_finalized', true)->get()->keyBy('year_month')
            : collect();

        $yms      = array_keys($monthlyGroups);
        $latestYm = end($yms) ?: $currentYm;

        // --- Anchor valuasi akhir bulan (mundur dari saldo live / snapshot terdekat) ---
        $endingUsdt = [];
        $endingIdr  = [];
        $anchor     = $liveValuationUsdt;

        foreach (array_reverse($yms) as $ym) {
            $snap = $snapshots->get($ym);

            if ($snap) {
                $endingUsdt[$ym] = (float)$snap->ending_valuation_usdt;
                $endingIdr[$ym]  = (float)$snap->ending_valuation_idr;
                $truePnl         = (float)$snap->true_net_pnl_usdt;
            } else {
                $endingUsdt[$ym] = $anchor;
                $endingIdr[$ym]  = $anchor * $usdtIdr;

                $floatingDelta = ($ym === $latestYm)
                    ? $floating['total_usdt'] - $this->floatingBaseline($ym, $yms, $snapshots)
                    : 0.0;

                $truePnl = $monthlyGroups[$ym]['net_pnl_usdt'] + $floatingDelta;
            }

            // Saldo awal bulan ini = saldo akhir bulan sebelumnya
            $anchor = $endingUsdt[$ym] - $truePnl;
        }

        // --- Susun baris laporan ---
        $cumulativePnlUsdt   = 0.0;
        $floatingRunningUsdt = 0.0;
        $prevValuationUsdt   = null;
        $reports = [];

        foreach ($monthlyGroups as $ym => $data) {
            $snap = $snapshots->get($ym);

            $row = $snap
                ? $this->rowFromSnapshot($snap, $data)
                : $this->rowFromLive(
                    $ym,
                    $data,
                    $floating,
                    $latestYm,
                    $usdtIdr,
                    $this->floatingBaseline($ym, $yms, $snapshots)
                );

            $row['portfolio_valuation_usdt'] = $endingUsdt[$ym];
            $row['portfolio_valuation_idr']  = $endingIdr[$ym];

            // Kurs terkunci untuk bulan yang sudah di-cut-off, kurs berjalan untuk sisanya
            $rate = ($snap && $snap->ending_valuation_usdt > 0)
                ? $snap->ending_valuation_idr / $snap->ending_valuation_usdt
                : $usdtIdr;

            // PERTUMBUHAN = kinerja trading murni (True Net PNL), BUKAN selisih saldo.
            // Selisih saldo ikut bergerak saat dana disuntik atau ditarik, dan itu
            // bukan hasil trading sehingga tidak boleh dihitung sebagai pertumbuhan.
            $growth = $row['true_net_pnl_usdt'];

            // Bagian perubahan saldo yang TIDAK dijelaskan oleh PNL = setoran bersih
            // (setoran dikurangi penarikan). Nilainya turunan dari angka-angka yang
            // sudah terkunci, jadi ikut immutable untuk bulan yang sudah di-cut-off.
            $netDepositUsdt = $prevValuationUsdt !== null
                ? ($endingUsdt[$ym] - $prevValuationUsdt) - $growth
                : 0.0;

            // Basis persentase: saldo awal bulan = saldo akhir bulan sebelumnya
            $basisUsdt = $prevValuationUsdt ?? ($endingUsdt[$ym] - $growth);

            $row['nominal_growth_usdt'] = $growth;
            $row['nominal_growth_idr']  = $row['true_net_pnl_idr'];
            $row['mom_growth_percent']  = $basisUsdt > 0 ? ($growth / $basisUsdt) * 100.0 : 0.0;
            $row['net_deposit_usdt']    = $netDepositUsdt;
            $row['net_deposit_idr']     = $netDepositUsdt * $rate;

            // POSISI floating pada akhir bulan ini = akumulasi seluruh perubahan
            // sampai bulan ini. Dilaporkan berdampingan dengan perubahannya supaya
            // "untung bulan ini" dan "di mana posisi terbuka berdiri" terbaca
            // bersamaan, tanpa perlu dihitung sendiri oleh pembaca.
            $floatingRunningUsdt += $row['floating_pnl_usdt'];
            $row['floating_running_usdt'] = $floatingRunningUsdt;
            $row['floating_running_idr']  = $floatingRunningUsdt * $rate;

            // Kumulatif dirantai ulang agar konsisten lintas snapshot
            $cumulativePnlUsdt += $row['realized_pnl_usdt'];
            $row['cumulative_pnl_usdt']      = $cumulativePnlUsdt;
            $row['cumulative_pnl_idr']       = $cumulativePnlUsdt * $usdtIdr;
            $row['true_cumulative_pnl_usdt'] = $cumulativePnlUsdt + $row['floating_pnl_usdt'];
            $row['true_cumulative_pnl_idr']  = ($cumulativePnlUsdt + $row['floating_pnl_usdt']) * $usdtIdr;

            $reports[] = $row;
            $prevValuationUsdt = $endingUsdt[$ym];
        }

        return [
            'reports'                 => $reports,
            'available_years'         => $realizedTrades['years'],
            'usdt_idr_rate'           => $usdtIdr,
            'total_floating_pnl_usdt' => $floating['total_usdt'],
            'total_floating_pnl_idr'  => $floating['total_usdt'] * $usdtIdr,
            'all_time_net_pnl_usdt'   => $cumulativePnlUsdt,
            'live_valuation_usdt'     => $liveValuationUsdt,
            'current_year_month'      => $currentYm,
            'latest_year_month'       => $latestYm,
        ];
    }

    /**
     * Satu baris laporan dari data live (bulan berjalan / bulan belum terkunci).
     *
     * @param float $floatingBaseline Floating PNL yang sudah terkunci di bulan sebelumnya.
     */
    protected function rowFromLive(
        string $ym,
        array $data,
        array $floating,
        string $latestYm,
        float $usdtIdr,
        float $floatingBaseline = 0.0
    ): array {
        // Yang dilaporkan adalah PERGERAKAN unrealized bulan ini, bukan akumulasi
        // sepanjang masa: total unrealized sekarang dikurangi yang sudah dibukukan
        // di snapshot bulan lalu. Tanpa ini, keuntungan mengambang yang sama akan
        // dihitung ulang tiap bulan.
        $floatingPnlUsdt = ($ym === $latestYm) ? ($floating['total_usdt'] - $floatingBaseline) : 0.0;
        $trueNetPnlUsdt  = $data['net_pnl_usdt'] + $floatingPnlUsdt;
        $winRate = $data['trades_count'] > 0 ? ($data['win_count'] / $data['trades_count']) * 100.0 : 0.0;

        $healthBadge = '🟢 Sehat (Profit Clean)';
        if ($trueNetPnlUsdt < 0) {
            $healthBadge = abs($floatingPnlUsdt) > abs($data['net_pnl_usdt'])
                ? '🔴 Floating Loss Dominan'
                : '🟡 Rugi Moderat';
        }

        $topAsset = '-';
        if (!empty($data['asset_pnls'])) {
            $pnls = $data['asset_pnls'];
            arsort($pnls);
            $topAsset = array_key_first($pnls);
        }

        return [
            'year_month'        => $ym,
            'year'              => $data['year'],
            'month_name'        => $data['month_name'],
            'trades_count'      => $data['trades_count'],
            'win_count'         => $data['win_count'],
            'loss_count'        => $data['loss_count'],
            'win_rate'          => $winRate,
            'profit_usdt'       => $data['profit_usdt'],
            'profit_idr'        => $data['profit_usdt'] * $usdtIdr,
            'loss_usdt'         => $data['loss_usdt'],
            'loss_idr'          => $data['loss_usdt'] * $usdtIdr,
            'realized_pnl_usdt' => $data['net_pnl_usdt'],
            'realized_pnl_idr'  => $data['net_pnl_usdt'] * $usdtIdr,
            'net_pnl_usdt'      => $data['net_pnl_usdt'],
            'net_pnl_idr'       => $data['net_pnl_usdt'] * $usdtIdr,
            'floating_pnl_usdt' => $floatingPnlUsdt,
            'floating_pnl_idr'  => $floatingPnlUsdt * $usdtIdr,
            'true_net_pnl_usdt' => $trueNetPnlUsdt,
            'true_net_pnl_idr'  => $trueNetPnlUsdt * $usdtIdr,
            'health_badge'      => $healthBadge,
            'top_asset'         => $topAsset,
            'is_finalized'      => false,
            'cut_off_date'      => null,
        ];
    }

    /**
     * Satu baris laporan dari snapshot yang sudah dikunci (immutable).
     */
    protected function rowFromSnapshot(MonthlyPortfolioSnapshot $snap, array $data): array
    {
        // Kurs yang terkunci saat cut-off, agar konversi IDR ikut immutable
        $lockedRate = $snap->ending_valuation_usdt > 0
            ? $snap->ending_valuation_idr / $snap->ending_valuation_usdt
            : 0.0;

        return [
            'year_month'        => $snap->year_month,
            'year'              => substr($snap->year_month, 0, 4),
            'month_name'        => $data['month_name'],
            'trades_count'      => (int)$snap->trades_count,
            'win_count'         => $data['win_count'],
            'loss_count'        => $data['loss_count'],
            'win_rate'          => (float)$snap->win_rate,
            'profit_usdt'       => $data['profit_usdt'],
            'profit_idr'        => $data['profit_usdt'] * $lockedRate,
            'loss_usdt'         => $data['loss_usdt'],
            'loss_idr'          => $data['loss_usdt'] * $lockedRate,
            'realized_pnl_usdt' => (float)$snap->realized_pnl_usdt,
            'realized_pnl_idr'  => (float)$snap->realized_pnl_idr,
            'net_pnl_usdt'      => (float)$snap->realized_pnl_usdt,
            'net_pnl_idr'       => (float)$snap->realized_pnl_idr,
            'floating_pnl_usdt' => (float)$snap->floating_pnl_usdt,
            'floating_pnl_idr'  => (float)$snap->floating_pnl_idr,
            'true_net_pnl_usdt' => (float)$snap->true_net_pnl_usdt,
            'true_net_pnl_idr'  => (float)$snap->true_net_pnl_idr,
            'health_badge'      => $snap->health_badge,
            'top_asset'         => $snap->top_asset ?? '-',
            'is_finalized'      => true,
            'cut_off_date'      => $snap->cut_off_date,
        ];
    }

    /**
     * Floating PNL yang sudah dibukukan di snapshot bulan sebelumnya.
     *
     * Dipakai sebagai titik nol supaya unrealized bulan berjalan dihitung sebagai
     * selisih, bukan akumulasi. Nol kalau bulan sebelumnya belum terkunci — dalam
     * kondisi itu tidak ada baseline yang sah, jadi floating dilaporkan penuh.
     *
     * @param array<int, string> $yms Daftar periode, urut kronologis.
     */
    protected function floatingBaseline(string $ym, array $yms, $snapshots): float
    {
        $pos = array_search($ym, $yms, true);

        if ($pos === false || $pos === 0) {
            return 0.0;
        }

        // Yang tersimpan di snapshot adalah PERUBAHAN floating per bulan, bukan
        // posisinya. Baseline yang benar adalah akumulasi seluruh bulan sebelumnya
        // — bukan hanya bulan terakhir. Membaca satu bulan terakhir saja kebetulan
        // benar selama bulan-bulan awal masih nol, lalu meleset setelahnya.
        $running = 0.0;

        for ($i = 0; $i < $pos; $i++) {
            $snap = $snapshots->get($yms[$i]);
            if ($snap) {
                $running += (float)$snap->floating_pnl_usdt;
            }
        }

        return $running;
    }

    /**
     * Floating (unrealized) PNL dari holding aktif saat ini.
     */
    protected function calculateFloatingPnl(array $portfolio, array $prices): array
    {
        $totalUsdt   = 0.0;
        $totalLoss   = 0.0;
        $totalProfit = 0.0;

        foreach ($portfolio['assets'] ?? [] as $assetData) {
            $assetName    = $assetData['asset'];
            $totalAmount  = (float)$assetData['total'];
            $currentPrice = (float)$assetData['price'];
            $valueUsdt    = (float)$assetData['value_usdt'];

            if (in_array($assetName, self::FIAT_STABLES) || $totalAmount <= 0) {
                continue;
            }

            // Sumber perhitungan modal yang sama dengan halaman Portofolio:
            // rata-rata berjalan, bukan rata-rata seluruh pembelian.
            $basis = $this->costBasisService->forAsset($assetName, $prices);

            $totalBuyAmount   = $basis['amount'];
            $totalBuyCostUsdt = $basis['cost_usdt'];

            $avgBuyPriceUsdt   = $totalBuyAmount > 0 ? ($totalBuyCostUsdt / $totalBuyAmount) : $currentPrice;
            $unrealizedPnlUsdt = $valueUsdt - ($totalAmount * $avgBuyPriceUsdt);

            $totalUsdt += $unrealizedPnlUsdt;
            if ($unrealizedPnlUsdt < 0) {
                $totalLoss += abs($unrealizedPnlUsdt);
            } else {
                $totalProfit += $unrealizedPnlUsdt;
            }
        }

        return [
            'total_usdt'  => $totalUsdt,
            'loss_usdt'   => $totalLoss,
            'profit_usdt' => $totalProfit,
        ];
    }

    /**
     * Realized PNL per transaksi SELL dalam rentang tanggal (inklusif, per hari).
     *
     * @return array<int, array{asset: string, pnl_usdt: float, date: Carbon}>
     */
    public function realizedTradesBetween(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to   = $to->copy()->endOfDay();

        $trades = $this->calculateRealizedTrades($this->tokocryptoService->getAllPrices())['trades'];

        return array_values(array_filter($trades, fn ($t) => $t['date']->between($from, $to)));
    }

    /**
     * Realized PNL per transaksi SELL memakai average cost berjalan.
     */
    protected function calculateRealizedTrades(array $prices): array
    {
        $trades = Trade::whereIn('type', ['BUY', 'SELL'])
            ->orderBy('trade_time', 'asc')
            ->get();

        $tradesByAsset = [];
        foreach ($trades as $trade) {
            $symbol = strtoupper(trim($trade->symbol));

            $base  = $symbol;
            $quote = 'USDT';
            foreach (['USDT', 'BIDR', 'IDRT', 'BUSD', 'USDC', 'BTC', 'ETH', 'BNB', 'IDR'] as $q) {
                if (str_ends_with($symbol, $q) && strlen($symbol) > strlen($q)) {
                    $base  = substr($symbol, 0, -strlen($q));
                    $quote = $q;
                    break;
                }
            }

            if (in_array($base, self::FIAT_STABLES)) {
                continue;
            }

            $trade->base_asset  = $base;
            $trade->quote_asset = $quote;
            $tradesByAsset[$base][] = $trade;
        }

        $realizedTrades = [];
        $availableYears = [];

        foreach ($tradesByAsset as $asset => $assetTrades) {
            $runningAmount   = 0.0;
            $runningCostUsdt = 0.0;

            foreach ($assetTrades as $trade) {
                $amount      = (float)$trade->amount;
                $priceInUsdt = $this->toUsdt((float)$trade->price, $trade->quote_asset, $prices, 16000.0);
                $type        = strtoupper(trim($trade->type));

                if ($type === 'BUY') {
                    $runningAmount   += $amount;
                    $runningCostUsdt += ($priceInUsdt * $amount);
                } elseif ($type === 'SELL') {
                    $avgBuyPriceUsdt = $runningAmount > 0 ? ($runningCostUsdt / $runningAmount) : 0.0;
                    $pnlUsdt = ($priceInUsdt - $avgBuyPriceUsdt) * $amount;

                    $tradeDate = Carbon::parse($trade->trade_time);
                    $year      = $tradeDate->format('Y');

                    if (!in_array($year, $availableYears)) {
                        $availableYears[] = $year;
                    }

                    $realizedTrades[] = [
                        'asset'      => $asset,
                        'pnl_usdt'   => $pnlUsdt,
                        'date'       => $tradeDate,
                        'year_month' => $tradeDate->format('Y-m'),
                        'year'       => $year,
                        'month_name' => $tradeDate->translatedFormat('F Y'),
                    ];

                    $runningAmount   = max(0.0, $runningAmount - $amount);
                    $runningCostUsdt = $runningAmount * $avgBuyPriceUsdt;
                }
            }
        }

        rsort($availableYears);

        return ['trades' => $realizedTrades, 'years' => $availableYears];
    }

    /**
     * Kelompokkan realized trades per bulan, urut kronologis.
     */
    protected function groupByMonth(array $realizedTrades): array
    {
        $groups = [];

        foreach ($realizedTrades as $rt) {
            $ym = $rt['year_month'];
            if (!isset($groups[$ym])) {
                $groups[$ym] = $this->emptyMonth($ym, $rt['year'], $rt['month_name']);
            }

            $groups[$ym]['trades_count']++;
            if ($rt['pnl_usdt'] > 0) {
                $groups[$ym]['win_count']++;
                $groups[$ym]['profit_usdt'] += $rt['pnl_usdt'];
            } else {
                $groups[$ym]['loss_count']++;
                $groups[$ym]['loss_usdt'] += abs($rt['pnl_usdt']);
            }

            $groups[$ym]['net_pnl_usdt'] += $rt['pnl_usdt'];
            $groups[$ym]['asset_pnls'][$rt['asset']] =
                ($groups[$ym]['asset_pnls'][$rt['asset']] ?? 0.0) + $rt['pnl_usdt'];
        }

        // Bulan berjalan harus selalu ada walau tanpa transaksi
        $currentYm = date('Y-m');
        if (!isset($groups[$currentYm])) {
            $now = now();
            $groups[$currentYm] = $this->emptyMonth($currentYm, $now->format('Y'), $now->translatedFormat('F Y'));
        }

        // Bulan yang sudah punya snapshot tapi tanpa trade juga tetap muncul
        foreach (MonthlyPortfolioSnapshot::pluck('year_month') as $ym) {
            if (!isset($groups[$ym])) {
                $date = Carbon::createFromFormat('Y-m-d', $ym . '-01');
                $groups[$ym] = $this->emptyMonth($ym, $date->format('Y'), $date->translatedFormat('F Y'));
            }
        }

        ksort($groups);

        return $groups;
    }

    protected function emptyMonth(string $ym, string $year, string $monthName): array
    {
        return [
            'year_month'   => $ym,
            'year'         => $year,
            'month_name'   => $monthName,
            'trades_count' => 0,
            'win_count'    => 0,
            'loss_count'   => 0,
            'profit_usdt'  => 0.0,
            'loss_usdt'    => 0.0,
            'net_pnl_usdt' => 0.0,
            'asset_pnls'   => [],
        ];
    }

    protected function toUsdt(float $price, string $quote, array $prices, float $fallbackRate): float
    {
        if (!in_array($quote, ['BIDR', 'IDRT', 'IDR'])) {
            return $price;
        }

        $rate = $prices['USDTIDR'] ?? ($prices['USDT' . $quote] ?? ($prices['USDTIDRT'] ?? $fallbackRate));

        return $rate > 100 ? ($price / $rate) : ($price / $fallbackRate);
    }
}
