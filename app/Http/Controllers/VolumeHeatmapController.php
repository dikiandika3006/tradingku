<?php

namespace App\Http\Controllers;

use App\Models\CoinglassVolumeSnapshot;
use App\Services\CoinglassService;
use Illuminate\Http\Request;

/**
 * Heatmap volume spot per bursa dari CoinGlass.
 *
 * Data diambil live saat halaman dibuka. Kalau API sedang tidak bisa dihubungi
 * atau key belum diisi, halaman jatuh ke snapshot terakhir yang tersimpan —
 * lebih berguna daripada layar kosong.
 */
class VolumeHeatmapController extends Controller
{
    protected $coinglass;

    public function __construct(CoinglassService $coinglass)
    {
        $this->coinglass = $coinglass;
    }

    public function index(Request $request)
    {
        $symbol = strtoupper(trim($request->input('symbol', 'BTC')));

        $hasil = $this->coinglass->spotVolumeHeatmap($symbol);

        $rows       = $hasil['rows'];
        $fetchedAt  = $hasil['fetched_at'];
        $isLive     = $hasil['ok'];
        $pesanError = $hasil['message'];

        // Jatuh ke snapshot terakhir bila pengambilan live gagal
        if (!$isLive) {
            $tersimpan = CoinglassVolumeSnapshot::latestCapture($symbol)
                ->orderByDesc('volume_usd_24h')
                ->get();

            if ($tersimpan->isNotEmpty()) {
                $fetchedAt = $tersimpan->first()->captured_at;
                $rows = $tersimpan->map(fn($r) => [
                    'symbol'        => $r->symbol,
                    'exchange_name' => $r->exchange_name,
                    'pair'          => $r->pair,
                    'price_usd'     => $r->price_usd,
                    'volume_usd_1h'  => $r->volume_usd_1h,
                    'volume_usd_4h'  => $r->volume_usd_4h,
                    'volume_usd_12h' => $r->volume_usd_12h,
                    'volume_usd_24h' => $r->volume_usd_24h,
                    'volume_usd_1w'  => $r->volume_usd_1w,
                    'price_change_percent_1h'  => $r->price_change_percent_1h,
                    'price_change_percent_4h'  => $r->price_change_percent_4h,
                    'price_change_percent_12h' => $r->price_change_percent_12h,
                    'price_change_percent_24h' => $r->price_change_percent_24h,
                    'price_change_percent_1w'  => $r->price_change_percent_1w,
                    'volume_change_percent_24h' => $r->volume_change_percent_24h,
                ])->all();
            }
        }

        // Total per timeframe, untuk baris ringkasan dan pembagi intensitas warna
        $total = [];
        $maks  = [];
        foreach (CoinglassVolumeSnapshot::TIMEFRAMES as $tf) {
            $nilai = array_map(fn($r) => (float)($r["volume_usd_{$tf}"] ?? 0), $rows);
            $total[$tf] = array_sum($nilai);
            $maks[$tf]  = $nilai ? max($nilai) : 0.0;
        }

        return view('derivatives.volume_heatmap', [
            'symbol'           => $symbol,
            'rows'             => $rows,
            'timeframes'       => CoinglassVolumeSnapshot::TIMEFRAMES,
            'timeframe_labels' => CoinglassVolumeSnapshot::TIMEFRAME_LABELS,
            'totals'           => $total,
            'maxima'           => $maks,
            'fetched_at'       => $fetchedAt,
            'is_live'          => $isLive,
            'error_message'    => $pesanError,
            'has_api_key'      => $this->coinglass->hasApiKey(),
            'snapshot_count'   => CoinglassVolumeSnapshot::where('symbol', $symbol)
                ->distinct('captured_at')->count('captured_at'),
        ]);
    }
}
