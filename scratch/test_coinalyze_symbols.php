<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = \App\Models\Setting::where('key', 'coinalyze_api_key')->value('value');
if (!$key) {
    echo "No API key found\n";
    exit;
}

// Fetch Coinalyze future markets
$res = \Illuminate\Support\Facades\Http::get("https://api.coinalyze.net/v1/future-markets", [
    'api_key' => $key
]);

echo "Status: " . $res->status() . "\n";
if ($res->successful()) {
    $markets = $res->json();
    echo "Total markets: " . count($markets) . "\n";
    foreach ($markets as $m) {
        $sym = $m['symbol'] ?? '';
        if (str_contains(strtoupper($sym), 'STRK')) {
            echo "Found: " . $sym . " => " . json_encode($m) . "\n";
        }
    }
}
