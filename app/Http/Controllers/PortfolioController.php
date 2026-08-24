<?php

namespace App\Http\Controllers;

use App\Models\Trade;
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
    public function index()
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

            // Find all BUY trades in database for this asset to calculate average buy price
            $buyTrades = Trade::where('symbol', 'like', $assetName . '%')
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
                    // Try to use active USDTIDR rate first as BIDR is stale on Tokocrypto
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
            $pnlPercent = $avgBuyPriceUsdt > 0 ? (($currentPrice - $avgBuyPriceUsdt) / $avgBuyPriceUsdt) * 100 : 0.0;

            // Find earliest BUY trade date to calculate holding duration
            $firstBuyTrade = Trade::where('symbol', 'like', $assetName . '%')
                ->where('type', 'BUY')
                ->orderBy('trade_time', 'asc')
                ->first();

            $firstBuyDate = $firstBuyTrade ? \Carbon\Carbon::parse($firstBuyTrade->trade_time) : null;
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
            $buyTrades = Trade::where('symbol', 'like', $assetName . '%')
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
     */
    public function monthlyReport(Request $request)
    {
        $selectedYear = $request->input('year', 'all');

        // Fetch all BUY and SELL trades ordered chronologically
        $trades = Trade::whereIn('type', ['BUY', 'SELL'])
            ->orderBy('trade_time', 'asc')
            ->get();

        $prices = $this->tokocryptoService->getAllPrices();
        $portfolio = $this->tokocryptoService->getPortfolio();
        $usdtIdr = $portfolio['usdt_idr_rate'] ?? 16000.0;

        $fiatStables = ['USDT', 'BIDR', 'IDRT', 'BUSD', 'USDC', 'IDR'];

        // 1. Calculate Floating PNL (Unrealized PNL) for current active holdings
        $totalFloatingPnlUsdt = 0.0;
        $totalFloatingLossUsdt = 0.0;
        $totalFloatingProfitUsdt = 0.0;

        foreach ($portfolio['assets'] ?? [] as $assetData) {
            $assetName = $assetData['asset'];
            $totalAmount = (float)$assetData['total'];
            $currentPrice = (float)$assetData['price'];
            $valueUsdt = (float)$assetData['value_usdt'];

            if (in_array($assetName, $fiatStables) || $totalAmount <= 0) {
                continue;
            }

            $buyTrades = Trade::where('symbol', 'like', $assetName . '%')
                ->where('type', 'BUY')
                ->get();

            $totalBuyAmount = 0.0;
            $totalBuyCostUsdt = 0.0;

            foreach ($buyTrades as $trade) {
                $tradeSymbol = $trade->symbol;
                $tradePrice = (float)$trade->price;
                $tradeAmount = (float)$trade->amount;

                $quote = 'USDT';
                foreach (['USDT', 'BIDR', 'IDRT', 'BUSD', 'IDR'] as $q) {
                    if (str_ends_with($tradeSymbol, $q) && strlen($tradeSymbol) > strlen($q)) {
                        $quote = $q;
                        break;
                    }
                }

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

            $avgBuyPriceUsdt = $totalBuyAmount > 0 ? ($totalBuyCostUsdt / $totalBuyAmount) : $currentPrice;
            $assetCostUsdt = $totalAmount * $avgBuyPriceUsdt;
            $unrealizedPnlUsdt = $valueUsdt - $assetCostUsdt;

            $totalFloatingPnlUsdt += $unrealizedPnlUsdt;
            if ($unrealizedPnlUsdt < 0) {
                $totalFloatingLossUsdt += abs($unrealizedPnlUsdt);
            } else {
                $totalFloatingProfitUsdt += $unrealizedPnlUsdt;
            }
        }

        // 2. Group trades by asset to calculate realized PNL using FIFO/Average Cost
        $tradesByAsset = [];
        foreach ($trades as $trade) {
            $symbol = strtoupper(trim($trade->symbol));
            
            $base = $symbol;
            $quote = 'USDT';
            foreach (['USDT', 'BIDR', 'IDRT', 'BUSD', 'USDC', 'BTC', 'ETH', 'BNB', 'IDR'] as $q) {
                if (str_ends_with($symbol, $q) && strlen($symbol) > strlen($q)) {
                    $base = substr($symbol, 0, -strlen($q));
                    $quote = $q;
                    break;
                }
            }

            if (in_array($base, $fiatStables)) {
                continue;
            }

            $trade->base_asset = $base;
            $trade->quote_asset = $quote;
            $tradesByAsset[$base][] = $trade;
        }

        $realizedTrades = [];
        $availableYears = [];

        foreach ($tradesByAsset as $asset => $assetTrades) {
            $runningAmount = 0.0;
            $runningCostUsdt = 0.0;

            foreach ($assetTrades as $trade) {
                $amount = (float)$trade->amount;
                $price = (float)$trade->price;
                $quote = $trade->quote_asset;

                $priceInUsdt = $price;
                if ($quote === 'BIDR' || $quote === 'IDRT' || $quote === 'IDR') {
                    $rate = $prices['USDTIDR'] ?? ($prices['USDT' . $quote] ?? ($prices['USDTIDRT'] ?? 16000.0));
                    if ($rate > 100) {
                        $priceInUsdt = $price / $rate;
                    } else {
                        $priceInUsdt = $price / 16000.0;
                    }
                }

                $type = strtoupper(trim($trade->type));

                if ($type === 'BUY') {
                    $runningAmount += $amount;
                    $runningCostUsdt += ($priceInUsdt * $amount);
                } elseif ($type === 'SELL') {
                    $avgBuyPriceUsdt = $runningAmount > 0 ? ($runningCostUsdt / $runningAmount) : 0.0;
                    $pnlUsdt = ($priceInUsdt - $avgBuyPriceUsdt) * $amount;
                    
                    $tradeDate = \Carbon\Carbon::parse($trade->trade_time);
                    $yearMonth = $tradeDate->format('Y-m');
                    $year = $tradeDate->format('Y');

                    if (!in_array($year, $availableYears)) {
                        $availableYears[] = $year;
                    }

                    $realizedTrades[] = [
                        'asset' => $asset,
                        'symbol' => $trade->symbol,
                        'amount' => $amount,
                        'sell_price_usdt' => $priceInUsdt,
                        'avg_buy_price_usdt' => $avgBuyPriceUsdt,
                        'pnl_usdt' => $pnlUsdt,
                        'trade_time' => $trade->trade_time,
                        'year_month' => $yearMonth,
                        'year' => $year,
                        'month_name' => $tradeDate->translatedFormat('F Y')
                    ];

                    $runningAmount = max(0.0, $runningAmount - $amount);
                    $runningCostUsdt = $runningAmount * $avgBuyPriceUsdt;
                }
            }
        }

        rsort($availableYears);

        // 3. Group realized trades by Month (chronologically)
        $currentYearMonth = date('Y-m');
        $monthlyGroups = [];

        foreach ($realizedTrades as $rt) {
            $ym = $rt['year_month'];
            if (!isset($monthlyGroups[$ym])) {
                $monthlyGroups[$ym] = [
                    'year_month' => $ym,
                    'year' => $rt['year'],
                    'month_name' => $rt['month_name'],
                    'trades_count' => 0,
                    'win_count' => 0,
                    'loss_count' => 0,
                    'profit_usdt' => 0.0,
                    'loss_usdt' => 0.0,
                    'net_pnl_usdt' => 0.0,
                    'asset_pnls' => []
                ];
            }

            $monthlyGroups[$ym]['trades_count']++;
            if ($rt['pnl_usdt'] > 0) {
                $monthlyGroups[$ym]['win_count']++;
                $monthlyGroups[$ym]['profit_usdt'] += $rt['pnl_usdt'];
            } else {
                $monthlyGroups[$ym]['loss_count']++;
                $monthlyGroups[$ym]['loss_usdt'] += abs($rt['pnl_usdt']);
            }

            $monthlyGroups[$ym]['net_pnl_usdt'] += $rt['pnl_usdt'];

            $ast = $rt['asset'];
            $monthlyGroups[$ym]['asset_pnls'][$ast] = ($monthlyGroups[$ym]['asset_pnls'][$ast] ?? 0.0) + $rt['pnl_usdt'];
        }

        // Ensure current active month exists in groups
        if (!isset($monthlyGroups[$currentYearMonth])) {
            $currentDate = now();
            $monthlyGroups[$currentYearMonth] = [
                'year_month' => $currentYearMonth,
                'year' => $currentDate->format('Y'),
                'month_name' => $currentDate->translatedFormat('F Y'),
                'trades_count' => 0,
                'win_count' => 0,
                'loss_count' => 0,
                'profit_usdt' => 0.0,
                'loss_usdt' => 0.0,
                'net_pnl_usdt' => 0.0,
                'asset_pnls' => []
            ];
        }

        ksort($monthlyGroups);

        // Initial Starting Capital Baseline (Total Cost / Asset Valuation)
        $initialCapitalUsdt = 0.0;
        foreach ($portfolio['assets'] ?? [] as $assetData) {
            $initialCapitalUsdt += (float)($assetData['value_usdt'] ?? 0);
        }
        if ($initialCapitalUsdt <= 0) {
            $initialCapitalUsdt = 1000.0; // Fallback baseline capital
        }

        // 4. Compute Cumulative Running Total, True Net Equity and True MoM Capital Growth
        $cumulativePnlUsdt = 0.0;
        $prevEquityUsdt = $initialCapitalUsdt;
        $monthlyReports = [];
        $latestYm = array_key_last($monthlyGroups);

        foreach ($monthlyGroups as $ym => $data) {
            $cumulativePnlUsdt += $data['net_pnl_usdt'];
            
            // Attach current floating PNL to the latest active month
            $floatingPnlUsdt = ($ym === $latestYm) ? $totalFloatingPnlUsdt : 0.0;
            $trueNetPnlUsdt = $data['net_pnl_usdt'] + $floatingPnlUsdt;
            $trueCumulativePnlUsdt = $cumulativePnlUsdt + $floatingPnlUsdt;

            $endingEquityUsdt = $initialCapitalUsdt + $trueCumulativePnlUsdt;

            // True MoM Capital Growth %: (Ending Equity - Starting Equity) / Starting Equity * 100%
            $momGrowthPercent = 0.0;
            if ($prevEquityUsdt > 0) {
                $momGrowthPercent = (($endingEquityUsdt - $prevEquityUsdt) / $prevEquityUsdt) * 100.0;
            }

            $winRate = $data['trades_count'] > 0 ? ($data['win_count'] / $data['trades_count']) * 100.0 : 0.0;

            // Health Status Indicator
            $healthBadge = '🟢 Sehat (Profit Clean)';
            if ($trueNetPnlUsdt < 0) {
                if (abs($floatingPnlUsdt) > abs($data['net_pnl_usdt'])) {
                    $healthBadge = '🔴 Floating Loss Dominan';
                } else {
                    $healthBadge = '🟡 Rugi Moderat';
                }
            }

            $topAsset = '-';
            if (!empty($data['asset_pnls'])) {
                arsort($data['asset_pnls']);
                $topAsset = array_key_first($data['asset_pnls']);
            }

            $monthlyReports[] = [
                'year_month' => $ym,
                'year' => $data['year'],
                'month_name' => $data['month_name'],
                'trades_count' => $data['trades_count'],
                'win_count' => $data['win_count'],
                'loss_count' => $data['loss_count'],
                'win_rate' => $winRate,
                'profit_usdt' => $data['profit_usdt'],
                'profit_idr' => $data['profit_usdt'] * $usdtIdr,
                'loss_usdt' => $data['loss_usdt'],
                'loss_idr' => $data['loss_usdt'] * $usdtIdr,
                'realized_pnl_usdt' => $data['net_pnl_usdt'],
                'realized_pnl_idr' => $data['net_pnl_usdt'] * $usdtIdr,
                'net_pnl_usdt' => $data['net_pnl_usdt'],
                'net_pnl_idr' => $data['net_pnl_usdt'] * $usdtIdr,
                'floating_pnl_usdt' => $floatingPnlUsdt,
                'floating_pnl_idr' => $floatingPnlUsdt * $usdtIdr,
                'true_net_pnl_usdt' => $trueNetPnlUsdt,
                'true_net_pnl_idr' => $trueNetPnlUsdt * $usdtIdr,
                'cumulative_pnl_usdt' => $cumulativePnlUsdt,
                'cumulative_pnl_idr' => $cumulativePnlUsdt * $usdtIdr,
                'true_cumulative_pnl_usdt' => $trueCumulativePnlUsdt,
                'true_cumulative_pnl_idr' => $trueCumulativePnlUsdt * $usdtIdr,
                'mom_growth_percent' => $momGrowthPercent,
                'health_badge' => $healthBadge,
                'top_asset' => $topAsset
            ];

            $prevEquityUsdt = $endingEquityUsdt;
        }

        // Apply Year Filter if specified
        $filteredReports = $monthlyReports;
        if ($selectedYear !== 'all') {
            $filteredReports = array_filter($monthlyReports, function($r) use ($selectedYear) {
                return $r['year'] === $selectedYear;
            });
            $filteredReports = array_values($filteredReports);
        }

        // Calculate Executive KPI Summary
        $allTimeNetPnlUsdt = $cumulativePnlUsdt;
        $trueNetPortfolioPnlUsdt = $cumulativePnlUsdt + $totalFloatingPnlUsdt;
        $bestMonth = null;
        $worstMonth = null;
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

        return view('portfolio.monthly_report', [
            'monthly_reports' => array_reverse($filteredReports), // Newest month first for table
            'chart_reports' => $filteredReports, // Chronological order for charts
            'available_years' => $availableYears,
            'selected_year' => $selectedYear,
            'all_time_net_pnl_usdt' => $allTimeNetPnlUsdt,
            'all_time_net_pnl_idr' => $allTimeNetPnlUsdt * $usdtIdr,
            'total_floating_pnl_usdt' => $totalFloatingPnlUsdt,
            'total_floating_pnl_idr' => $totalFloatingPnlUsdt * $usdtIdr,
            'true_net_portfolio_pnl_usdt' => $trueNetPortfolioPnlUsdt,
            'true_net_portfolio_pnl_idr' => $trueNetPortfolioPnlUsdt * $usdtIdr,
            'best_month' => $bestMonth,
            'worst_month' => $worstMonth,
            'avg_monthly_win_rate' => $avgMonthlyWinRate,
            'usdt_idr_rate' => $usdtIdr
        ]);
    }
}
