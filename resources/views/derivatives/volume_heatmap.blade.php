@extends('layouts.app')

@section('title', 'Heatmap Volume Spot')

@section('content')
@php
    // Intensitas warna relatif terhadap bursa terbesar di kolom yang sama.
    // Akar kuadrat dipakai supaya bursa kecil tidak tenggelam jadi gelap semua.
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
        <h1 style="margin: 0; font-size: 1.8rem; font-weight: 700;">Volume Spot Terakumulasi &mdash; {{ $symbol }}</h1>
        <p style="margin-top: 0.25rem; color: var(--text-muted);">
            Dijumlahkan dari {{ count($exchanges) }} bursa, langsung dari API publik masing-masing.
            Candle jam berjalan tidak dihitung agar angkanya tidak berubah-ubah sepanjang jam.
        </p>
    </div>

    <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
        @if($is_live)
            <span class="badge" style="background: rgba(34,197,94,0.15); color: #22c55e; padding: 0.35rem 0.7rem; border-radius: 6px; font-weight: 600; font-size: 0.8rem;">&#9679; LIVE</span>
        @else
            <span class="badge" style="background: rgba(234,179,8,0.15); color: #eab308; padding: 0.35rem 0.7rem; border-radius: 6px; font-weight: 600; font-size: 0.8rem;">&#9888; REKAMAN TERAKHIR</span>
        @endif

        @if($price_usd)
            <span style="color: #fff; font-weight: 600; font-family: monospace;">${{ number_format($price_usd, 2) }}</span>
        @endif

        @if($fetched_at)
            <span style="color: var(--text-muted); font-size: 0.85rem;">
                {{ \Carbon\Carbon::parse($fetched_at)->translatedFormat('d M Y H:i') }}
            </span>
        @endif
    </div>
</div>

@if(!$is_live && $error_message)
    <div class="glass-card" style="padding: 1.25rem; margin-bottom: 1.5rem; border: 1px solid rgba(239,68,68,0.35); background: rgba(239,68,68,0.06);">
        <strong style="color: #ef4444;">Pengambilan live gagal.</strong>
        <div style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.35rem;">{{ $error_message }}</div>
    </div>
@elseif(!empty($failures))
    <div class="glass-card" style="padding: 1rem 1.25rem; margin-bottom: 1.5rem; border: 1px solid rgba(234,179,8,0.3); background: rgba(234,179,8,0.05);">
        <strong style="color: #eab308; font-size: 0.9rem;">Sebagian bursa dilewati:</strong>
        <span style="color: var(--text-muted); font-size: 0.85rem;">{{ implode(' · ', $failures) }}</span>
    </div>
@endif

<!-- Akumulasi seluruh bursa -->
<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    @foreach($timeframes as $tf)
        <div class="glass-card" style="padding: 1.15rem; {{ $tf === '24h' ? 'border: 1px solid rgba(59,130,246,0.4); background: rgba(59,130,246,0.05);' : '' }}">
            <div style="color: {{ $tf === '24h' ? '#60a5fa' : 'var(--text-muted)' }}; font-size: 0.85rem; font-weight: {{ $tf === '24h' ? '600' : '500' }};">
                Akumulasi {{ $timeframe_labels[$tf] }}
            </div>
            <div style="margin-top: 0.5rem; font-size: 1.45rem; font-weight: 700; color: #fff;">
                {{ $usd($totals[$tf] ?? 0) }}
            </div>
        </div>
    @endforeach
</div>

<!-- Rincian per bursa -->
<div class="glass-card" style="padding: 1.5rem; margin-bottom: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;">
        <h3 style="font-size: 1.15rem; font-weight: 600; color: #fff; margin: 0;">Rincian per Bursa</h3>
        <span style="font-size: 0.85rem; color: var(--text-muted);">Kontribusi terhadap akumulasi di atas</span>
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
                    <th style="padding: 0.75rem 1rem; text-align: right;">Pangsa 24 Jam</th>
                </tr>
            </thead>
            <tbody>
                @forelse($exchanges as $e)
                    @php
                        $pangsa = ($totals['24h'] ?? 0) > 0
                            ? ($e['volume_usd_24h'] / $totals['24h']) * 100 : 0;
                    @endphp
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.9rem;">
                        <td style="padding: 0.9rem 1rem; font-weight: 600; color: #fff;">
                            {{ $e['exchange_name'] }}
                            @if(!empty($e['pair']))
                                <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 400; font-family: monospace;">{{ $e['pair'] }}</div>
                            @endif
                        </td>
                        <td style="padding: 0.9rem 1rem; text-align: right; font-family: monospace; color: #fff;">
                            {{ $e['price_usd'] !== null ? '$' . number_format($e['price_usd'], 2) : '-' }}
                        </td>

                        @foreach($timeframes as $tf)
                            @php
                                $vol = $e["volume_usd_{$tf}"] ?? null;
                                $alpha = $intensitas($vol, $maxima[$tf] ?? 0);
                            @endphp
                            <td style="padding: 0.9rem 1rem; text-align: right; font-family: monospace; color: #fff; font-weight: 600;
                                       background: rgba(59,130,246,{{ number_format($alpha * 0.30, 3) }});">
                                {{ $usd($vol) }}
                            </td>
                        @endforeach

                        <td style="padding: 0.9rem 1rem; text-align: right; font-family: monospace; color: var(--text-muted);">
                            {{ number_format($pangsa, 1) }}%
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($timeframes) + 3 }}" style="text-align: center; padding: 3rem 0; color: var(--text-muted);">
                            Belum ada data volume. Jalankan <code>php artisan volume:capture-spot</code>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Riwayat harian -->
<div class="glass-card" style="padding: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;">
        <h3 style="font-size: 1.15rem; font-weight: 600; color: #fff; margin: 0;">Riwayat Harian (Akumulasi)</h3>
        <span style="font-size: 0.85rem; color: var(--text-muted);">{{ $daily->count() }} hari terekam</span>
    </div>

    <div class="table-responsive" style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); color: var(--text-muted); font-size: 0.85rem;">
                    <th style="padding: 0.75rem 1rem;">Tanggal</th>
                    <th style="padding: 0.75rem 1rem; text-align: center;">Bursa</th>
                    @foreach($timeframes as $tf)
                        <th style="padding: 0.75rem 1rem; text-align: right;">{{ $timeframe_labels[$tf] }}</th>
                    @endforeach
                    <th style="padding: 0.75rem 1rem; text-align: right;">Perubahan 24 Jam</th>
                </tr>
            </thead>
            <tbody>
                @forelse($daily as $i => $d)
                    @php
                        $sebelumnya = $daily[$i + 1] ?? null;
                        $delta = null;
                        if ($sebelumnya && $sebelumnya->volume_usd_24h > 0) {
                            $delta = (($d->volume_usd_24h - $sebelumnya->volume_usd_24h) / $sebelumnya->volume_usd_24h) * 100;
                        }
                    @endphp
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.9rem;">
                        <td style="padding: 0.8rem 1rem; font-weight: 600; color: #fff;">
                            {{ \Carbon\Carbon::parse($d->captured_date)->translatedFormat('d M Y') }}
                        </td>
                        <td style="padding: 0.8rem 1rem; text-align: center; color: var(--text-muted);">{{ $d->exchange_count }}</td>
                        @foreach($timeframes as $tf)
                            <td style="padding: 0.8rem 1rem; text-align: right; font-family: monospace; color: #fff;">
                                {{ $usd($d->{"volume_usd_{$tf}"}) }}
                            </td>
                        @endforeach
                        <td style="padding: 0.8rem 1rem; text-align: right; font-family: monospace; font-weight: 600;
                                   color: {{ $delta === null ? 'var(--text-muted)' : ($delta >= 0 ? '#22c55e' : '#ef4444') }};">
                            {{ $delta === null ? '-' : ($delta >= 0 ? '+' : '') . number_format($delta, 2) . '%' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($timeframes) + 3 }}" style="text-align: center; padding: 2.5rem 0; color: var(--text-muted);">
                            Riwayat harian belum terbentuk. Cron akan merekam satu baris tiap hari.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top: 1rem; font-size: 0.78rem; color: var(--text-muted); line-height: 1.6;">
        Bursa hanya menyimpan candle beberapa ratus jam ke belakang, jadi riwayat jangka panjang
        dibentuk sendiri lewat rekaman harian ini. Satu baris per hari; menjalankan ulang di hari
        yang sama akan memperbarui baris itu, bukan menambah baris baru.
    </div>
</div>
@endsection
