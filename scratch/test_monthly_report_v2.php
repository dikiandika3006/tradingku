<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Trade;
use App\Services\TokocryptoService;

$service = app(TokocryptoService::class);
$trades = Trade::whereIn('type', ['BUY', 'SELL'])->orderBy('trade_time', 'asc')->get();
$prices = $service->getAllPrices();
$portfolio = $service->getPortfolio();
$usdtIdr = $portfolio['usdt_idr_rate'] ?? 16000.0;
$fiatStables = ['USDT', 'BIDR', 'IDRT', 'BUSD', 'USDC', 'IDR'];

// 1. Calculate Floating PNL for currently held assets
$totalFloatingPnlUsdt = 0.0;
$totalFloatingLossUsdt = 0.0;
$totalFloatingProfitUsdt = 0.0;
$holdingAssetSummaries = [];

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
            $rate = $prices['USDTIDR'] ?? ($prices['USDT' . $quote] ?? 16000.0);
            if ($rate > 100) {
                $priceInUsdt = $tradePrice / $rate;
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

    $holdingAssetSummaries[$assetName] = [
        'asset' => $assetName,
        'amount' => $totalAmount,
        'avg_buy_price' => $avgBuyPriceUsdt,
        'current_price' => $currentPrice,
        'unrealized_pnl' => $unrealizedPnlUsdt,
        'unrealized_pnl_idr' => $unrealizedPnlUsdt * $usdtIdr
    ];
}

// 2. Realized PNL Calculation
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
            $priceInUsdt = $price / $usdtIdr;
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

// Ensure current active month is present in monthly groups
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

$cumulativePnlUsdt = 0.0;
$monthlyReports = [];
$latestYm = array_key_last($monthlyGroups);

foreach ($monthlyGroups as $ym => $data) {
    $cumulativePnlUsdt += $data['net_pnl_usdt'];
    
    // Attach current floating PNL to the latest active month
    $floatingPnlUsdt = ($ym === $latestYm) ? $totalFloatingPnlUsdt : 0.0;
    $trueNetPnlUsdt = $data['net_pnl_usdt'] + $floatingPnlUsdt;
    $trueCumulativePnlUsdt = $cumulativePnlUsdt + $floatingPnlUsdt;

    $winRate = $data['trades_count'] > 0 ? ($data['win_count'] / $data['trades_count']) * 100.0 : 0.0;

    // Health Status Label
    $healthStatus = 'HEALTHY';
    $healthBadge = '🟢 Sehat (Profit)';
    if ($trueNetPnlUsdt < 0) {
        if (abs($floatingPnlUsdt) > abs($data['net_pnl_usdt'])) {
            $healthStatus = 'FLOATING_LOSS_DOMINANT';
            $healthBadge = '🔴 Floating Loss Dominan';
        } else {
            $healthStatus = 'LOSS_MODERATE';
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
        'win_rate' => $winRate,
        'realized_pnl_usdt' => $data['net_pnl_usdt'],
        'realized_pnl_idr' => $data['net_pnl_usdt'] * $usdtIdr,
        'floating_pnl_usdt' => $floatingPnlUsdt,
        'floating_pnl_idr' => $floatingPnlUsdt * $usdtIdr,
        'true_net_pnl_usdt' => $trueNetPnlUsdt,
        'true_net_pnl_idr' => $trueNetPnlUsdt * $usdtIdr,
        'cumulative_pnl_usdt' => $cumulativePnlUsdt,
        'true_cumulative_pnl_usdt' => $trueCumulativePnlUsdt,
        'true_cumulative_pnl_idr' => $trueCumulativePnlUsdt * $usdtIdr,
        'health_badge' => $healthBadge,
        'top_asset' => $topAsset
    ];
}

echo "Total Floating PNL: $" . number_format($totalFloatingPnlUsdt, 2) . "\n";
echo "Processed Monthly Reports Count: " . count($monthlyReports) . "\n";
print_r(end($monthlyReports));
