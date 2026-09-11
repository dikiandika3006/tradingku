<?php

namespace App\Http\Controllers;

use App\Models\Trade;
use App\Services\CostBasisService;
use App\Services\MonthlyReportService;
use App\Services\TokocryptoService;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    protected $tokocryptoService;

    public function __construct(TokocryptoService $tokocryptoService)
    {
        $this->tokocryptoService = $tokocryptoService;
    }

    /**
     * Display portfolio page and detailed asset statistics.
     */
    public function index(CostBasisService $costBasisService)
    {
        // Fetch current balances (either from API or mock database balances)
        $portfolio = $this->tokocryptoService->getPortfolio();
        
        // Fetch all prices to help convert currencies
        $prices = $this->tokocryptoService->getAllPrices();
        $usdtIdr = $portfolio['usdt_idr_rate'];

        $detailedAssets = [];
        $totalCostUsdt = 0.0;
        $totalCurrentValuationUsdt = 0.0;

        foreach ($portfolio['assets'] as $assetData) {
            $assetName = $assetData['asset'];
            $totalAmount = $assetData['total'];
            $currentPrice = $assetData['price'];
            $valueUsdt = $assetData['value_usdt'];

            // Modal sisa posisi memakai rata-rata berjalan: penjualan mengurangi
            // kolam biaya, sehingga posisi yang sempat tutup tidak lagi menyeret
            // harga beli lamanya ke dalam rata-rata posisi yang baru dibuka.
            $basis = $costBasisService->forAsset($assetName, $prices);

            $avgBuyPriceUsdt = $basis['avg_price_usdt'];

            // Tanpa riwayat transaksi, anggap modal = harga pasar (PNL nol)
            if ($avgBuyPriceUsdt <= 0.0) {
                $avgBuyPriceUsdt = $currentPrice;
            }

            // Calculate cost and PNL for current holdings
            $assetCostUsdt = $totalAmount * $avgBuyPriceUsdt;
            $unrealizedPnlUsdt = $valueUsdt - $assetCostUsdt;
            $pnlPercent = $avgBuyPriceUsdt > 0 ? (($currentPrice - $avgBuyPriceUsdt) / $avgBuyPriceUsdt) * 100 : 0.0;

            // Durasi dihitung sejak posisi yang SEKARANG dibuka, bukan sejak
            // pembelian pertama yang mungkin sudah lama dijual habis.
            $firstBuyDate = $basis['opened_at'];
            $durationText = '-';
            if ($firstBuyDate) {
                $now = now();
                $days = (int)$firstBuyDate->diffInDays($now);
                if ($days > 0) {
                    $durationText = $days . ' hari';
                } else {
                    $hours = (int)$firstBuyDate->diffInHours($now);
                    if ($hours > 0) {
                        $durationText = $hours . ' jam';
                    } else {
                        $durationText = 'Hari ini';
                    }
                }
            }

            $totalCostUsdt += $assetCostUsdt;
            $totalCurrentValuationUsdt += $valueUsdt;

            $detailedAssets[] = [
                'asset' => $assetName,
                'free' => $assetData['free'],
                'locked' => $assetData['locked'],
                'total' => $totalAmount,
                'avg_buy_price_usdt' => $avgBuyPriceUsdt,
                'avg_buy_price_idr' => $avgBuyPriceUsdt * $usdtIdr,
                'current_price_usdt' => $currentPrice,
                'current_price_idr' => $currentPrice * $usdtIdr,
                'cost_usdt' => $assetCostUsdt,
                'cost_idr' => $assetCostUsdt * $usdtIdr,
                'value_usdt' => $valueUsdt,
                'value_idr' => $valueUsdt * $usdtIdr,
                'pnl_usdt' => $unrealizedPnlUsdt,
                'pnl_idr' => $unrealizedPnlUsdt * $usdtIdr,
                'pnl_percent' => $pnlPercent,
                'duration_text' => $durationText,
                'first_buy_date' => $firstBuyDate ? $firstBuyDate->format('Y-m-d') : null,
                'source' => $assetData['source']
            ];
        }

        // Calculate overall portfolio PNL
        $totalPnlUsdt = $totalCurrentValuationUsdt - $totalCostUsdt;
        $totalPnlPercent = $totalCostUsdt > 0 ? ($totalPnlUsdt / $totalCostUsdt) * 100 : 0.0;

        return view('portfolio.index', [
            'assets' => $detailedAssets,
            'total_valuation_usdt' => $totalCurrentValuationUsdt,
            'total_valuation_idr' => $totalCurrentValuationUsdt * $usdtIdr,
            'total_cost_usdt' => $totalCostUsdt,
            'total_cost_idr' => $totalCostUsdt * $usdtIdr,
            'total_pnl_usdt' => $totalPnlUsdt,
            'total_pnl_idr' => $totalPnlUsdt * $usdtIdr,
            'total_pnl_percent' => $totalPnlPercent,
            'usdt_idr_rate' => $usdtIdr,
            'is_live' => $portfolio['is_live']
        ]);
    }

    /**
     * Display realized Profit & Loss page.
     */
    public function pnl(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Fetch all BUY and SELL trades ordered chronologically
        $trades = Trade::whereIn('type', ['BUY', 'SELL'])
            ->orderBy('trade_time', 'asc')
            ->get();

        $prices = $this->tokocryptoService->getAllPrices();
        
        // Fetch current USDT IDR rate
        $portfolio = $this->tokocryptoService->getPortfolio();
        $usdtIdr = $portfolio['usdt_idr_rate'] ?? 16000.0;

        $fiatStables = ['USDT', 'BIDR', 'IDRT', 'BUSD', 'USDC', 'IDR'];

        // Group trades by base asset
        $tradesByAsset = [];
        foreach ($trades as $trade) {
            $symbol = strtoupper(trim($trade->symbol));
            
            // Find base asset
            $base = $symbol;
            $quote = 'USDT';
            foreach (['USDT', 'BIDR', 'IDRT', 'BUSD', 'USDC', 'BTC', 'ETH', 'BNB', 'IDR'] as $q) {
                if (str_ends_with($symbol, $q) && strlen($symbol) > strlen($q)) {
                    $base = substr($symbol, 0, -strlen($q));
                    $quote = $q;
                    break;
                }
            }

            // Exclude USDT, IDR and stablecoins
            if (in_array($base, $fiatStables)) {
                continue;
            }

            $trade->base_asset = $base;
            $trade->quote_asset = $quote;
            $tradesByAsset[$base][] = $trade;
        }

        $allRealizedPnl = [];
        $assetSummaries = [];

        foreach ($tradesByAsset as $asset => $assetTrades) {
            $runningAmount = 0.0;
            $runningCostUsdt = 0.0;

            $totalAssetSold = 0.0;
            $totalAssetPnlUsdt = 0.0;
            $totalAssetCostUsdt = 0.0;
 
            foreach ($assetTrades as $trade) {
                $amount = (float)$trade->amount;
                $price = (float)$trade->price;
                $quote = $trade->quote_asset;
 
                // Standardize trade price to USDT
                $priceInUsdt = $price;
                if ($quote === 'BIDR' || $quote === 'IDRT' || $quote === 'IDR') {
                    $rate = $prices['USDTIDR'] ?? ($prices['USDT' . $quote] ?? ($prices['USDTIDRT'] ?? 16000.0));
                    if ($rate > 100) {
                        $priceInUsdt = $price / $rate;
                    }
                }
 
                $type = strtoupper(trim($trade->type));
 
                if ($type === 'BUY') {
                    $runningAmount += $amount;
                    $runningCostUsdt += ($priceInUsdt * $amount);
                } elseif ($type === 'SELL') {
                    $avgBuyPriceUsdt = $runningAmount > 0 ? ($runningCostUsdt / $runningAmount) : 0.0;
                    
                    // Realized PNL for this sell
                    $pnlUsdt = ($priceInUsdt - $avgBuyPriceUsdt) * $amount;
                    $costUsdt = $avgBuyPriceUsdt * $amount;
 
                    // Apply date filter
                    $tradeTime = date('Y-m-d', strtotime($trade->trade_time));
                    $isInRange = true;
                    if ($startDate && $tradeTime < $startDate) {
                        $isInRange = false;
                    }
                    if ($endDate && $tradeTime > $endDate) {
                        $isInRange = false;
                    }
 
                    if ($isInRange) {
                        $pnlPercent = $avgBuyPriceUsdt > 0 ? (($priceInUsdt - $avgBuyPriceUsdt) / $avgBuyPriceUsdt) * 100 : 0.0;
                        $allRealizedPnl[] = [
                            'id' => $trade->id,
                            'asset' => $asset,
                            'symbol' => $trade->symbol,
                            'amount' => $amount,
                            'sell_price_usdt' => $priceInUsdt,
                            'avg_buy_price_usdt' => $avgBuyPriceUsdt,
                            'pnl_usdt' => $pnlUsdt,
                            'pnl_percent' => $pnlPercent,
                            'trade_time' => $trade->trade_time,
                            'notes' => $trade->notes
                        ];
 
                        $totalAssetSold += $amount;
                        $totalAssetPnlUsdt += $pnlUsdt;
                        $totalAssetCostUsdt += $costUsdt;
                    }
 
                    // Adjust holdings after sell
                    $runningAmount = max(0.0, $runningAmount - $amount);
                    $runningCostUsdt = $runningAmount * $avgBuyPriceUsdt;
                }
            }
 
            if ($totalAssetSold > 0) {
                $assetSummaries[$asset] = [
                    'asset' => $asset,
                    'total_sold' => $totalAssetSold,
                    'pnl_usdt' => $totalAssetPnlUsdt,
                    'pnl_idr' => $totalAssetPnlUsdt * $usdtIdr,
                    'pnl_percent' => $totalAssetCostUsdt > 0 ? ($totalAssetPnlUsdt / $totalAssetCostUsdt) * 100 : 0.0
                ];
            }
        }

        // Sort realized trades by trade time descending
        usort($allRealizedPnl, function ($a, $b) {
            return strcmp($b['trade_time'], $a['trade_time']);
        });

        // Calculate grand totals
        $totalProfitUsdt = 0.0;
        $totalLossUsdt = 0.0;
        foreach ($allRealizedPnl as $pnl) {
            if ($pnl['pnl_usdt'] > 0) {
                $totalProfitUsdt += $pnl['pnl_usdt'];
            } else {
                $totalLossUsdt += abs($pnl['pnl_usdt']);
            }
        }
        $netPnlUsdt = $totalProfitUsdt - $totalLossUsdt;

        // Fetch current balances and prices to calculate unrealized loss (floating loss)
        $portfolio = $this->tokocryptoService->getPortfolio();
        $prices = $this->tokocryptoService->getAllPrices();
        $totalUnrealizedLossUsdt = 0.0;

        foreach ($portfolio['assets'] ?? [] as $assetData) {
            $assetName = $assetData['asset'];
            $totalAmount = $assetData['total'];
            $currentPrice = $assetData['price'];
            $valueUsdt = $assetData['value_usdt'];

            // Find all BUY trades in database for this asset to calculate average buy price
            $buyTrades = Trade::forBaseAsset($assetName)
                ->where('type', 'BUY')
                ->get();

            $totalBuyAmount = 0.0;
            $totalBuyCostUsdt = 0.0;

            foreach ($buyTrades as $trade) {
                $tradeSymbol = $trade->symbol;
                $tradePrice = (float)$trade->price;
                $tradeAmount = (float)$trade->amount;

                // Detect quote currency of trade (e.g. BIDR, USDT, IDRT)
                $quote = 'USDT';
                foreach (['USDT', 'BIDR', 'IDRT', 'BUSD', 'IDR'] as $q) {
                    if (str_ends_with($tradeSymbol, $q) && strlen($tradeSymbol) > strlen($q)) {
                        $quote = $q;
                        break;
                    }
                }

                // Standardize trade price to USDT
                $priceInUsdt = $tradePrice;
                if ($quote === 'BIDR' || $quote === 'IDRT' || $quote === 'IDR') {
                    $rate = $prices['USDTIDR'] ?? ($prices['USDT' . $quote] ?? ($prices['USDTIDRT'] ?? 16400.0));
                    if ($rate > 100) {
                        $priceInUsdt = $tradePrice / $rate;
                    } else {
                        $priceInUsdt = $tradePrice / 16400.0;
                    }
                }

                $totalBuyAmount += $tradeAmount;
                $totalBuyCostUsdt += ($priceInUsdt * $tradeAmount);
            }

            // Weighted average buy price in USDT
            $avgBuyPriceUsdt = $totalBuyAmount > 0 ? ($totalBuyCostUsdt / $totalBuyAmount) : 0.0;

            // If we have no BUY trades logged, fallback average price to current price
            if ($avgBuyPriceUsdt === 0.0) {
                $avgBuyPriceUsdt = $currentPrice;
            }

            // Calculate cost and PNL for current holdings
            $assetCostUsdt = $totalAmount * $avgBuyPriceUsdt;
            $unrealizedPnlUsdt = $valueUsdt - $assetCostUsdt;

            // Only add negative PNL (assets that are minus)
            if ($unrealizedPnlUsdt < 0) {
                $totalUnrealizedLossUsdt += abs($unrealizedPnlUsdt);
            }
        }

        return view('portfolio.pnl', [
            'pnl_records' => $allRealizedPnl,
            'asset_summaries' => $assetSummaries,
            'total_profit_usdt' => $totalProfitUsdt,
            'total_profit_idr' => $totalProfitUsdt * $usdtIdr,
            'total_loss_usdt' => $totalLossUsdt,
            'total_loss_idr' => $totalLossUsdt * $usdtIdr,
            'net_pnl_usdt' => $netPnlUsdt,
            'net_pnl_idr' => $netPnlUsdt * $usdtIdr,
            'unrealized_loss_usdt' => $totalUnrealizedLossUsdt,
            'unrealized_loss_idr' => $totalUnrealizedLossUsdt * $usdtIdr,
            'usdt_idr_rate' => $usdtIdr,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
    }

    /**
     * Display open orders page.
     */
    public function openOrders(Request $request)
    {
        $hasCredentials = $this->tokocryptoService->hasCredentials();
        $openOrders = [];
        $prices = [];
        $usdtIdr = 16000.0;

        if ($hasCredentials) {
            $openOrders = $this->tokocryptoService->getOpenOrders();
            $prices = $this->tokocryptoService->getAllPrices();
            $portfolio = $this->tokocryptoService->getPortfolio();
            $usdtIdr = $portfolio['usdt_idr_rate'] ?? 16000.0;

            // Enforce sorting by order time descending (newest first)
            usort($openOrders, function ($a, $b) {
                return $b['time'] <=> $a['time'];
            });

            // Map and calculate distance % to current price for limit orders
            foreach ($openOrders as &$order) {
                $symbol = $order['symbol'];
                $currentPrice = $prices[$symbol] ?? 0.0;
                $orderPrice = (float)$order['price'];
                
                $order['current_price'] = $currentPrice;
                $order['price'] = $orderPrice;
                $order['origQty'] = (float)$order['origQty'];
                $order['executedQty'] = (float)$order['executedQty'];
                $order['total_usdt'] = $orderPrice * $order['origQty'];
                
                // Calculate distance percentage to fill
                if ($currentPrice > 0) {
                    $order['distance_percent'] = (($orderPrice - $currentPrice) / $currentPrice) * 100.0;
                } else {
                    $order['distance_percent'] = null;
                }
            }
        }

        return view('portfolio.open_orders', [
            'has_credentials' => $hasCredentials,
            'open_orders' => $openOrders,
            'usdt_idr_rate' => $usdtIdr
        ]);
    }


    /**
     * Display Month-to-Month (MoM) Cumulative Portfolio Movement Report with Floating PNL & True Net Equity.
     *
     * Perhitungan didelegasikan ke MonthlyReportService (Monthly Portfolio Snapshot Engine):
     * bulan yang sudah di-cut-off dibaca dari snapshot (immutable), bulan berjalan dihitung live.
     */
    public function monthlyReport(Request $request, MonthlyReportService $reportService)
    {
        $selectedYear = $request->input('year', 'all');

        $data           = $reportService->build();
        $monthlyReports = $data['reports'];
        $usdtIdr        = $data['usdt_idr_rate'];

        // Apply Year Filter if specified
        $filteredReports = $monthlyReports;
        if ($selectedYear !== 'all') {
            $filteredReports = array_values(array_filter($monthlyReports, function ($r) use ($selectedYear) {
                return $r['year'] === $selectedYear;
            }));
        }

        // Calculate Executive KPI Summary
        $allTimeNetPnlUsdt      = $data['all_time_net_pnl_usdt'];
        $totalFloatingPnlUsdt   = $data['total_floating_pnl_usdt'];
        $trueNetPortfolioPnlUsdt = $allTimeNetPnlUsdt + $totalFloatingPnlUsdt;

        $bestMonth       = null;
        $worstMonth      = null;
        $totalWinRateSum = 0.0;

        foreach ($monthlyReports as $r) {
            if ($bestMonth === null || $r['true_net_pnl_usdt'] > $bestMonth['true_net_pnl_usdt']) {
                $bestMonth = $r;
            }
            if ($worstMonth === null || $r['true_net_pnl_usdt'] < $worstMonth['true_net_pnl_usdt']) {
                $worstMonth = $r;
            }
            $totalWinRateSum += $r['win_rate'];
        }

        $avgMonthlyWinRate = count($monthlyReports) > 0 ? ($totalWinRateSum / count($monthlyReports)) : 0.0;

        // Status cut-off engine untuk ditampilkan di header laporan
        $finalizedCount = 0;
        foreach ($monthlyReports as $r) {
            if (!empty($r['is_finalized'])) {
                $finalizedCount++;
            }
        }

        return view('portfolio.monthly_report', [
            'monthly_reports'           => array_reverse($filteredReports), // Newest month first for table
            'chart_reports'             => $filteredReports, // Chronological order for charts
            'available_years'           => $data['available_years'],
            'selected_year'             => $selectedYear,
            'all_time_net_pnl_usdt'     => $allTimeNetPnlUsdt,
            'all_time_net_pnl_idr'      => $allTimeNetPnlUsdt * $usdtIdr,
            'total_floating_pnl_usdt'   => $totalFloatingPnlUsdt,
            'total_floating_pnl_idr'    => $totalFloatingPnlUsdt * $usdtIdr,
            'true_net_portfolio_pnl_usdt' => $trueNetPortfolioPnlUsdt,
            'true_net_portfolio_pnl_idr'  => $trueNetPortfolioPnlUsdt * $usdtIdr,
            'best_month'                => $bestMonth,
            'worst_month'               => $worstMonth,
            'avg_monthly_win_rate'      => $avgMonthlyWinRate,
            'usdt_idr_rate'             => $usdtIdr,
            'current_year_month'        => $data['current_year_month'],
            'finalized_months_count'    => $finalizedCount,
        ]);
    }
}
