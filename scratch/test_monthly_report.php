<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Trade;

$trades = Trade::whereIn('type', ['BUY', 'SELL'])->orderBy('trade_time', 'asc')->get();
$usdtIdr = 16000.0;
$fiatStables = ['USDT', 'BIDR', 'IDRT', 'BUSD', 'USDC', 'IDR'];

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

rsort($availableYears);

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

ksort($monthlyGroups);

$cumulativePnlUsdt = 0.0;
$prevCumulativePnlUsdt = 0.0;
$monthlyReports = [];

foreach ($monthlyGroups as $ym => $data) {
    $cumulativePnlUsdt += $data['net_pnl_usdt'];
    
    $winRate = $data['trades_count'] > 0 ? ($data['win_count'] / $data['trades_count']) * 100.0 : 0.0;

    $momGrowthPercent = 0.0;
    if ($prevCumulativePnlUsdt != 0) {
        $momGrowthPercent = (($cumulativePnlUsdt - $prevCumulativePnlUsdt) / abs($prevCumulativePnlUsdt)) * 100.0;
    } elseif ($cumulativePnlUsdt != 0) {
        $momGrowthPercent = 100.0;
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
        'net_pnl_usdt' => $data['net_pnl_usdt'],
        'net_pnl_idr' => $data['net_pnl_usdt'] * $usdtIdr,
        'cumulative_pnl_usdt' => $cumulativePnlUsdt,
        'cumulative_pnl_idr' => $cumulativePnlUsdt * $usdtIdr,
        'mom_growth_percent' => $momGrowthPercent,
        'top_asset' => $topAsset
    ];

    $prevCumulativePnlUsdt = $cumulativePnlUsdt;
}

echo "Total Trades Found: " . count($trades) . "\n";
echo "Total Realized Trades: " . count($realizedTrades) . "\n";
echo "Total Months Processed: " . count($monthlyReports) . "\n";
echo "Cumulative Net PNL: $" . number_format($cumulativePnlUsdt, 2) . "\n";
print_r($monthlyReports);
