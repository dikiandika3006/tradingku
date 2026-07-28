<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$symbol = "STRKUSDT";
$cleanSym = "STRKUSDT";
$baseCoin = "STRK";
$currentPrice = 0.0306;

$aggregatedVol = 0.0;
$aggregatedOI = 0.0;

// 1. Bybit Futures
try {
    $bybitRes = \Illuminate\Support\Facades\Http::timeout(4)->withoutVerifying()->get("https://api.bybit.com/v5/market/tickers", [
        'category' => 'linear',
        'symbol' => $cleanSym
    ]);
    if ($bybitRes->successful()) {
        $item = $bybitRes->json()['result']['list'][0] ?? [];
        if (isset($item['turnover24h'])) {
            $v = (float)$item['turnover24h'];
            $oi = (float)($item['openInterest'] ?? 0) * (float)($item['lastPrice'] ?? $currentPrice);
            echo "Bybit Vol: $" . number_format($v / 1000000, 2) . "M | OI: $" . number_format($oi / 1000000, 2) . "M\n";
            $aggregatedVol += $v;
            $aggregatedOI += $oi;
        }
    }
} catch (\Exception $e) {
    echo "Bybit Failed: " . $e->getMessage() . "\n";
}

// 2. OKX Swap Futures
try {
    $okxRes = \Illuminate\Support\Facades\Http::timeout(4)->withoutVerifying()->get("https://www.okx.com/api/v5/market/ticker", [
        'instId' => $baseCoin . '-USDT-SWAP'
    ]);
    if ($okxRes->successful()) {
        $item = $okxRes->json()['data'][0] ?? [];
        if (isset($item['volCcy24h'])) {
            $v = (float)$item['volCcy24h'] * $currentPrice;
            echo "OKX Vol: $" . number_format($v / 1000000, 2) . "M\n";
            $aggregatedVol += $v;
        }
    }
} catch (\Exception $e) {
    echo "OKX Failed: " . $e->getMessage() . "\n";
}

// 3. KuCoin Futures
try {
    $kcRes = \Illuminate\Support\Facades\Http::timeout(4)->get("https://api-futures.kucoin.com/api/v1/contracts/{$baseCoin}USDTM");
    if ($kcRes->successful()) {
        $kcData = $kcRes->json()['data'] ?? [];
        if (isset($kcData['turnoverOf24h'])) {
            $v = (float)$kcData['turnoverOf24h'];
            $oi = (float)($kcData['openInterest'] ?? 0) * (float)($kcData['markPrice'] ?? $currentPrice);
            echo "KuCoin Vol: $" . number_format($v / 1000000, 2) . "M | OI: $" . number_format($oi / 1000000, 2) . "M\n";
            $aggregatedVol += $v;
            $aggregatedOI += $oi;
        }
    }
} catch (\Exception $e) {
    echo "KuCoin Failed: " . $e->getMessage() . "\n";
}

// 4. Binance Data API
try {
    $binanceRes = \Illuminate\Support\Facades\Http::timeout(4)->get("https://data-api.binance.vision/api/v3/ticker/24hr", [
        'symbol' => $cleanSym
    ]);
    if ($binanceRes->successful()) {
        $item = $binanceRes->json();
        if (isset($item['quoteVolume'])) {
            $v = (float)$item['quoteVolume'];
            echo "Binance Vol: $" . number_format($v / 1000000, 2) . "M\n";
            $aggregatedVol += $v;
        }
    }
} catch (\Exception $e) {
    echo "Binance Failed: " . $e->getMessage() . "\n";
}

echo "========================================\n";
echo "Total Sum Volume: $" . number_format($aggregatedVol / 1000000, 2) . "M\n";
echo "Total Sum OI: $" . number_format($aggregatedOI / 1000000, 2) . "M\n";
