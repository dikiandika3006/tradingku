<?php

$url = "https://api.bybit.com/v5/market/tickers?category=linear&symbol=STRKUSDT";
$opts = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0\r\n"
    ],
    "ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false
    ]
];
$context = stream_context_create($opts);
$json = @file_get_contents($url, false, $context);
if ($json) {
    $data = json_decode($json, true);
    $item = $data['result']['list'][0] ?? [];
    echo "Bybit Turnover (24h Vol): $" . number_format((float)($item['turnover24h'] ?? 0) / 1000000, 2) . "M\n";
    echo "Bybit Open Interest USD: $" . number_format(((float)($item['openInterest'] ?? 0) * (float)($item['lastPrice'] ?? 0)) / 1000000, 2) . "M\n";
} else {
    echo "file_get_contents failed\n";
}
