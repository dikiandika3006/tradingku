<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Test bytick.com
try {
    $res = \Illuminate\Support\Facades\Http::timeout(5)
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0'])
        ->withoutVerifying()
        ->get("https://api.bytick.com/v5/market/tickers", [
            'category' => 'linear',
            'symbol' => 'STRKUSDT'
        ]);

    echo "bytick Status: " . $res->status() . "\n";
    if ($res->successful()) {
        $item = $res->json()['result']['list'][0] ?? [];
        echo "Bybit Turnover (24h Vol): $" . number_format((float)($item['turnover24h'] ?? 0) / 1000000, 2) . "M\n";
        echo "Bybit Open Interest USD: $" . number_format(((float)($item['openInterest'] ?? 0) * (float)($item['lastPrice'] ?? 0)) / 1000000, 2) . "M\n";
    }
} catch (\Exception $e) {
    echo "bytick Error: " . $e->getMessage() . "\n";
}
