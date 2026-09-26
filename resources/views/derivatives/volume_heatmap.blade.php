@extends('layouts.app')

@section('title', 'Heatmap Volume Spot')

@section('content')
@php
    // Intensitas warna sel dihitung relatif terhadap bursa terbesar di kolom itu,
    // memakai akar kuadrat supaya bursa kecil tidak tenggelam jadi hitam semua.
    $intensitas = function ($nilai, $maks) {
        if (!$maks || !$nilai || $nilai <= 0) return 0.0;
        return min(1.0, sqrt($nilai / $maks));
    };

    $usd = function ($v) {
        if ($v === null) return '-';
        $a = abs($v);
        if ($a >= 1e9) return '$' . number_format($v / 1e9, 2) . 'B';
        if ($a >= 1e6) return '$' . number_format($v / 1e6, 2) . 'M';
        if ($a >= 1e3) return '$' . number_format($v / 1e3, 2) . 'K';
        return '$' . number_format($v, 2);
    };
@endphp

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
    <div class="page-title">
        <h1 style="margin: 0; font-size: 1.8rem; font-weight: 700;">Heatmap Volume Spot &mdash; {{ $symbol }}</h1>
        <p style="margin-top: 0.25rem; color: var(--text-muted);">
            Volume perdagangan spot per bursa pada rentang 1 jam hingga 7 hari, bersumber dari CoinGlass.
        </p>
    </div>

    <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
        @if($is_live)
            <span class="badge" style="background: rgba(34,197,94,0.15); color: #22c55e; padding: 0.35rem 0.7rem; border-radius: 6px; font-weight: 600; font-size: 0.8rem;">
                &#9679; LIVE
            </span>
        @else
            <span class="badge" style="background: rgba(234,179,8,0.15); color: #eab308; padding: 0.35rem 0.7rem; border-radius: 6px; font-weight: 600; font-size: 0.8rem;">
                &#9888; DATA TERSIMPAN
            </span>
        @endif

        @if($fetched_at)
            <span style="color: var(--text-muted); font-size: 0.85rem;">
                {{ \Carbon\Carbon::parse($fetched_at)->translatedFormat('d M Y H:i') }}
            </span>
        @endif
    </div>
</div>

@if(!$has_api_key)
    <div class="glass-card" style="padding: 1.25rem; margin-bottom: 1.5rem; border: 1px solid rgba(234,179,8,0.35); background: rgba(234,179,8,0.06);">
        <strong style="color: #eab308;">CoinGlass API key belum diisi.</strong>
        <div style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.35rem;">
            Isi di <a href="{{ route('setting.index') }}" style="color: var(--color-secondary); text-decoration: underline;">API Settings</a> agar heatmap bisa mengambil data.
        </div>
    </div>
@elseif(!$is_live && $error_message)
    <div class="glass-card" style="padding: 1.25rem; margin-bottom: 1.5rem; border: 1px solid rgba(239,68,68,0.35); background: rgba(239,68,68,0.06);">
        <strong style="color: #ef4444;">Pengambilan live gagal.</strong>
        <div style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.35rem;">{{ $error_message }}</div>
    </div>
@endif

<!-- Ringkasan total per timeframe -->
<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    @foreach($timeframes as $tf)
        <div class="glass-card" style="padding: 1.15rem;">
            <div style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">
                Total Volume ({{ $timeframe_labels[$tf] }})
            </div>
            <div style="margin-top: 0.5rem; font-size: 1.45rem; font-weight: 700; color: #fff;">
                {{ $usd($totals[$tf] ?? 0) }}
            </div>
        </div>
    @endforeach
</div>

<div class="glass-card" style="padding: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;">
        <h3 style="font-size: 1.15rem; font-weight: 600; color: #fff; margin: 0;">
            Volume per Bursa
        </h3>
        <span style="font-size: 0.85rem; color: var(--text-muted);">
            {{ count($rows) }} bursa &middot; {{ $snapshot_count }} snapshot tersimpan
        </span>
    </div>

    <div class="table-responsive" style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); color: var(--text-muted); font-size: 0.85rem;">
                    <th style="padding: 0.75rem 1rem;">Bursa</th>
                    <th style="padding: 0.75rem 1rem; text-align: right;">Harga</th>
                    @foreach($timeframes as $tf)
                        <th style="padding: 0.75rem 1rem; text-align: right;">{{ $timeframe_labels[$tf] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.9rem;">
                        <td style="padding: 0.9rem 1rem; font-weight: 600; color: #fff;">
                            {{ $row['exchange_name'] }}
                            @if(!empty($row['pair']))
                                <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 400; font-family: monospace;">
                                    {{ $row['pair'] }}
                                </div>
                            @endif
                        </td>
                        <td style="padding: 0.9rem 1rem; text-align: right; font-family: monospace; color: #fff;">
                            {{ $row['price_usd'] !== null ? '$' . number_format($row['price_usd'], 2) : '-' }}
                        </td>

                        @foreach($timeframes as $tf)
                            @php
                                $vol = $row["volume_usd_{$tf}"] ?? null;
                                $chg = $row["price_change_percent_{$tf}"] ?? null;
                                $alpha = $intensitas($vol, $maxima[$tf] ?? 0);
                                // Hijau bila harga naik pada rentang itu, merah bila turun
                                $rgb = $chg === null ? '99,102,241' : ($chg >= 0 ? '34,197,94' : '239,68,68');
                            @endphp
                            <td style="padding: 0.9rem 1rem; text-align: right; font-family: monospace;
                                       background: rgba({{ $rgb }}, {{ number_format($alpha * 0.32, 3) }});">
                                <span style="color: #fff; font-weight: 600;">{{ $usd($vol) }}</span>
                                @if($chg !== null)
                                    <div style="font-size: 0.72rem; font-weight: 500; color: {{ $chg >= 0 ? '#22c55e' : '#ef4444' }};">
                                        {{ $chg >= 0 ? '+' : '' }}{{ number_format($chg, 2) }}%
                                    </div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($timeframes) + 2 }}" style="text-align: center; padding: 3rem 0; color: var(--text-muted);">
                            Belum ada data volume. Pastikan API key sudah diisi, lalu jalankan
                            <code>php artisan coinglass:capture-volume</code>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top: 1rem; font-size: 0.78rem; color: var(--text-muted); line-height: 1.6;">
        Kepekatan warna sel menunjukkan besar volume relatif terhadap bursa terbesar di kolom yang sama.
        Warna hijau berarti harga naik pada rentang tersebut, merah berarti turun.
    </div>
</div>
@endsection
