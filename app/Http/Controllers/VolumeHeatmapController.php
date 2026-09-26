<?php

namespace App\Http\Controllers;

use App\Models\SpotVolumeSnapshot;
use App\Services\SpotVolumeService;
use Illuminate\Http\Request;

/**
 * Heatmap volume spot terakumulasi dari beberapa bursa.
 *
 * Data diambil live saat halaman dibuka. Bila semua bursa gagal dihubungi,
 * halaman jatuh ke rekaman harian terakhir — lebih berguna daripada layar kosong.
 */
class VolumeHeatmapController extends Controller
{
    protected $volume;

    public function __construct(SpotVolumeService $volume)
    {
        $this->volume = $volume;
    }

    public function index(Request $request)
    {
        $symbol = strtoupper(trim($request->input('symbol', 'BTC')));

        $hasil = $this->volume->aggregate($symbol);

        $exchanges = $hasil['exchanges'];
        $totals    = $hasil['totals'];
        $changes   = $hasil['changes'];
        $fetchedAt = $hasil['fetched_at'];
        $isLive    = $hasil['ok'];

        // Jatuh ke rekaman harian terakhir bila pengambilan live gagal
        if (!$isLive) {
            $tersimpan = SpotVolumeSnapshot::latestDay($symbol)
                ->orderByDesc('volume_usd_24h')
                ->get();

            if ($tersimpan->isNotEmpty()) {
                $fetchedAt = $tersimpan->first()->captured_at;

                $exchanges = $tersimpan->map(fn($r) => [
                    'exchange_name'  => $r->exchange_name,
                    'pair'           => $r->pair,
                    'price_usd'      => $r->price_usd,
                    'volume_usd_1h'  => $r->volume_usd_1h,
                    'volume_usd_4h'  => $r->volume_usd_4h,
                    'volume_usd_12h' => $r->volume_usd_12h,
                    'volume_usd_24h' => $r->volume_usd_24h,
                    'volume_usd_1w'  => $r->volume_usd_1w,
                    'volume_change_percent_1h'  => $r->volume_change_percent_1h,
                    'volume_change_percent_4h'  => $r->volume_change_percent_4h,
                    'volume_change_percent_12h' => $r->volume_change_percent_12h,
                    'volume_change_percent_24h' => $r->volume_change_percent_24h,
                    'volume_change_percent_1w'  => $r->volume_change_percent_1w,
                ])->all();

                foreach (SpotVolumeSnapshot::TIMEFRAMES as $tf) {
                    $sekarang = array_sum(array_column($exchanges, "volume_usd_{$tf}"));

                    $sebelumnya = 0.0;
                    foreach ($exchanges as $e) {
                        $pct = $e["volume_change_percent_{$tf}"];
                        $sebelumnya += $pct === null ? 0.0 : $e["volume_usd_{$tf}"] / (1 + $pct / 100);
                    }

                    $totals[$tf]  = $sekarang;
                    $changes[$tf] = $sebelumnya > 0
                        ? (($sekarang - $sebelumnya) / $sebelumnya) * 100.0
                        : null;
                }
            }
        }

        // Pembagi intensitas warna: bursa terbesar di tiap kolom
        $maxima = [];
        foreach (SpotVolumeSnapshot::TIMEFRAMES as $tf) {
            $nilai = array_map(fn($r) => (float)($r["volume_usd_{$tf}"] ?? 0), $exchanges);
            $maxima[$tf] = $nilai ? max($nilai) : 0.0;
        }

        return view('derivatives.volume_heatmap', [
            'symbol'           => $symbol,
            'exchanges'        => $exchanges,
            'totals'           => $totals,
            'changes'          => $changes,
            'maxima'           => $maxima,
            'timeframes'       => SpotVolumeSnapshot::TIMEFRAMES,
            'timeframe_labels' => SpotVolumeSnapshot::TIMEFRAME_LABELS,
            'price_usd'        => $hasil['price_usd'],
            'fetched_at'       => $fetchedAt,
            'is_live'          => $isLive,
            'error_message'    => $hasil['message'],
            'failures'         => $hasil['failures'],
            'daily'            => SpotVolumeSnapshot::dailyTotals($symbol, 14),
            'supported'        => $this->volume->supportedSymbols(),
        ]);
    }
}
