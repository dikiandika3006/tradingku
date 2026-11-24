@extends('layouts.app')

@section('title', 'Portofolio Detail')

@section('content')
<div class="page-header">
    <div class="page-title">
        <div class="judul-baris">
            <h1>Portofolio Detail</h1>
            <div class="mode-tabel" role="group" aria-label="Mode tampilan">
                <button type="button" class="btn btn-sm" data-mode="super">Super Simple</button>
                <button type="button" class="btn btn-sm" data-mode="simple">Simple</button>
                <button type="button" class="btn btn-sm" data-mode="full">Full</button>
            </div>
        </div>
        <p>Pantau saldo kepemilikan aset, harga rata-rata beli (average price), serta keuntungan/kerugian belum terealisasi</p>
    </div>
    <div class="header-actions" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
        <span id="countdownBadge" class="badge badge-neutral" style="font-family: monospace; font-size: 0.75rem; background: rgba(var(--tint-rgb), 0.08); border: 1px solid var(--border-light); display: none;">
            Refresh: <span id="countdownTimer">120</span>s
        </span>
        <span class="badge {{ $is_live ? 'badge-success' : 'badge-danger' }}">
            {{ $is_live ? 'Koneksi API Tokocrypto: LIVE' : 'Mode Fallback: Database Trade Logs' }}
        </span>
        @if($boleh_sinkron ?? false)
            <form method="POST" action="{{ route('portfolio.sync-tokocrypto') }}" style="display: flex; align-items: center; gap: 0.5rem; margin: 0;"
                  onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Menyinkronkan…';">
                @csrf
                <span style="font-size: 0.75rem; color: var(--text-muted);">
                    Sinkron jurnal: {{ $sinkron_terakhir ? $sinkron_terakhir->format('d M H:i') . ' WIB' : 'belum pernah' }}
                </span>
                <button type="submit" class="btn btn-secondary" style="padding: 0.35rem 0.85rem; font-size: 0.8rem;">Sinkron sekarang</button>
            </form>
        @endif
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success mb-4">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger mb-4">{{ session('error') }}</div>
@endif

<!-- Portfolio Performance Boxes -->
<div class="stats-grid stats-porto">
    <!-- Card 1: Valuation -->
    <div class="glass-card">
        <div class="card-title">
            <span><span class="lbl-panjang">Nilai Portofolio Saat Ini</span><span class="lbl-pendek">Nilai</span></span>
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="stat-value text-success">${{ number_format($total_valuation_usdt, 2) }}</div>
        <div class="stat-desc">≈ Rp {{ number_format($total_valuation_idr, 0, ',', '.') }}</div>
    </div>

    <!-- Card 2: Cost Basis -->
    <div class="glass-card">
        <div class="card-title">
            <span><span class="lbl-panjang">Modal Investasi (Cost Basis)</span><span class="lbl-pendek">Modal</span></span>
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
        </div>
        <div class="stat-value" style="color: var(--text-main);">${{ number_format($total_cost_usdt, 2) }}</div>
        <div class="stat-desc">≈ Rp {{ number_format($total_cost_idr, 0, ',', '.') }}</div>
    </div>

    <!-- Card 3: Unrealized Profit/Loss -->
    <div class="glass-card">
        <div class="card-title">
            <span><span class="lbl-panjang">PNL Belum Terealisasi (Unrealized PNL)</span><span class="lbl-pendek">PNL</span></span>
            @if($total_pnl_usdt >= 0)
                <span class="badge badge-success">PROFIT</span>
            @else
                <span class="badge badge-danger">LOSS</span>
            @endif
        </div>
        <div class="stat-value {{ $total_pnl_usdt >= 0 ? 'text-success' : 'text-danger' }}">
            {{ $total_pnl_usdt >= 0 ? '+' : '-' }}${{ number_format(abs($total_pnl_usdt), 2) }}
            <span class="pnl-pct" style="font-size: 1.1rem; font-weight: 600; margin-left: 0.25rem;">
                ({{ $total_pnl_usdt >= 0 ? '+' : '' }}{{ number_format($total_pnl_percent, 2) }}%)
            </span>
        </div>
        <div class="stat-desc {{ $total_pnl_usdt >= 0 ? 'text-success' : 'text-danger' }}">
            ≈ {{ $total_pnl_usdt >= 0 ? '+' : '-' }}Rp {{ number_format(abs($total_pnl_idr), 0, ',', '.') }}
        </div>
    </div>
</div>

<!-- Detailed Asset Holdings Table -->
<div class="glass-card mb-6">
    <div class="card-title" style="flex-wrap: wrap; gap: 0.75rem;">
        <span>Rincian Kepemilikan Aset</span>
        <!-- Di ponsel judul kolom disembunyikan, jadi urutan dipilih dari sini -->
        <div class="sort-mobile" style="gap: 0.5rem; align-items: center;">
            <select id="sortKeySelect" class="form-control" style="padding: 0.45rem 0.75rem; font-size: 0.85rem; width: auto;">
                <option value="">Urutan bawaan</option>
            </select>
            <button type="button" id="sortDirBtn" class="btn btn-secondary btn-sm" title="Balik arah urutan">↓</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="custom-table table-kartu" id="assetTable">
            <thead>
                <tr>
                    <th class="th-sort" data-sort-key="asset" data-sort-type="text">Aset</th>
                    <th class="th-sort kol-full" data-sort-key="saldo">Jumlah Saldo</th>
                    <th class="th-sort kol-full" data-sort-key="durasi">Durasi</th>
                    <th class="th-sort kol-biasa" data-sort-key="avg">Harga Rata-rata Beli</th>
                    <th class="th-sort kol-biasa" data-sort-key="harga">Harga Pasar</th>
                    <th class="th-sort kol-biasa" data-sort-key="modal">Modal (Cost Basis)</th>
                    <th class="th-sort kol-biasa" data-sort-key="nilai">Nilai Saat Ini</th>
                    <th class="th-sort kol-biasa" data-sort-key="pnl">PNL Belum Terealisasi</th>
                    <th class="th-sort kol-biasa" data-sort-key="pnlpct">PNL (%)</th>
                    <th class="th-sort kol-super" data-sort-key="harga">Beli<br><span class="sub-th">Pasar (USD)</span></th>
                    <th class="th-sort kol-super" data-sort-key="pnl">Modal<br><span class="sub-th">PNL (Rp)</span></th>
                    <th class="th-sort kol-full" data-sort-key="sumber" data-sort-type="text">Sumber</th>
                </tr>
            </thead>
            <tbody>
                @forelse($assets as $asset)
                    @php $dinilai = $asset['can_be_valued'] ?? true; @endphp
                    {{-- Nilai mentah untuk sorting; kosong = tidak bisa dinilai, selalu ditaruh paling bawah --}}
                    <tr @class(['aset-tersembunyi' => $asset['tersembunyi'] ?? false]) data-sort-asset="{{ $asset['asset'] }}"
                        data-sort-saldo="{{ $asset['total'] }}"
                        data-sort-durasi="{{ $asset['first_buy_date'] ? (int) floor((time() - strtotime($asset['first_buy_date'])) / 86400) : '' }}"
                        data-sort-avg="{{ $asset['avg_buy_price_usdt'] }}"
                        data-sort-harga="{{ $asset['current_price_usdt'] }}"
                        data-sort-modal="{{ $dinilai ? $asset['cost_usdt'] : '' }}"
                        data-sort-nilai="{{ $asset['value_usdt'] }}"
                        data-sort-pnl="{{ $dinilai ? $asset['pnl_usdt'] : '' }}"
                        data-sort-pnlpct="{{ $dinilai ? $asset['pnl_percent'] : '' }}"
                        data-sort-sumber="{{ $asset['source'] }}">
                        <td class="kartu-judul" style="font-weight: 800; font-size: 1.1rem; color: var(--color-secondary);">
                            {{ $asset['asset'] }}
                        </td>
                        <td class="kol-full" data-label="Jumlah Saldo" style="font-family: monospace; font-weight: 600;">
                            {{ number_format($asset['total'], 6) }}
                            <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 400; margin-top: 0.15rem;">
                                Tersedia: {{ number_format($asset['free'], 4) }} | Order: {{ number_format($asset['locked'], 4) }}
                            </div>
                        </td>
                        <td class="kol-full" data-label="Durasi">
                            <span class="badge" style="background: rgba(var(--tint-rgb), 0.06); border: 1px solid rgba(var(--tint-rgb), 0.1); color: var(--text-main); font-weight: 600; padding: 0.35rem 0.65rem; border-radius: 6px; display: inline-block;">
                                {{ $asset['duration_text'] }}
                            </span>
                            @if($asset['first_buy_date'])
                                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.25rem;">
                                    Sejak: {{ $asset['first_buy_date'] }}
                                </div>
                            @endif
                        </td>
                        <td class="kol-biasa" data-label="Rata-rata Beli" style="font-family: monospace;">
                            ${{ number_format($asset['avg_buy_price_usdt'], 4) }}
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;">
                                Rp {{ number_format($asset['avg_buy_price_idr'], 0, ',', '.') }}
                            </div>
                        </td>
                        <td class="kol-biasa" data-label="Harga Pasar" style="font-family: monospace; font-weight: 500;">
                            ${{ number_format($asset['current_price_usdt'], 4) }}
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;">
                                Rp {{ number_format($asset['current_price_idr'], 0, ',', '.') }}
                            </div>
                        </td>
                        <td class="kol-biasa" data-label="Modal" style="font-family: monospace;">
                            @if($asset['can_be_valued'] ?? true)
                                ${{ number_format($asset['cost_usdt'], 2) }}
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;">
                                    Rp {{ number_format($asset['cost_idr'], 0, ',', '.') }}
                                </div>
                            @else
                                <span style="color: var(--text-muted);">&mdash;</span>
                            @endif
                        </td>
                        <td class="kol-biasa" data-label="Nilai Saat Ini" style="font-family: monospace; font-weight: 600;">
                            ${{ number_format($asset['value_usdt'], 2) }}
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;">
                                Rp {{ number_format($asset['value_idr'], 0, ',', '.') }}
                            </div>
                        </td>
                        <td data-label="PNL Belum Terealisasi" style="font-family: monospace; font-weight: 600;" class="kol-biasa {{ ($asset['can_be_valued'] ?? true) ? ($asset['pnl_usdt'] >= 0 ? 'text-success' : 'text-danger') : '' }}">
                            @if(!($asset['can_be_valued'] ?? true))
                                <span style="color: var(--text-muted);">&mdash;</span>
                            @elseif($asset['pnl_usdt'] >= 0)
                                +${{ number_format($asset['pnl_usdt'], 2) }}
                                <div style="font-size: 0.75rem; margin-top: 0.15rem;">
                                    +Rp {{ number_format($asset['pnl_idr'], 0, ',', '.') }}
                                </div>
                            @else
                                -${{ number_format(abs($asset['pnl_usdt']), 2) }}
                                <div style="font-size: 0.75rem; margin-top: 0.15rem;">
                                    -Rp {{ number_format(abs($asset['pnl_idr']), 0, ',', '.') }}
                                </div>
                            @endif
                        </td>
                        <td data-label="PNL (%)" style="font-family: monospace; font-weight: 700;" class="kol-biasa {{ ($asset['can_be_valued'] ?? true) ? ($asset['pnl_percent'] >= 0 ? 'text-success' : 'text-danger') : '' }}">
                            @if($asset['can_be_valued'] ?? true)
                                {{ $asset['pnl_percent'] >= 0 ? '+' : '' }}{{ number_format($asset['pnl_percent'], 2) }}%
                            @else
                                <span style="color: var(--text-muted);" title="Tidak punya harga pasar, jadi tidak bisa dinilai">tidak dinilai</span>
                            @endif
                        </td>
                        {{-- Kolom mode Super Simple --}}
                        @php
                            // Harga >= $1 cukup 2 desimal supaya kolom tidak melebar
                            $usd = fn($v) => '$' . number_format($v, abs($v) >= 1 ? 2 : 4);
                        @endphp
                        <td class="kol-super">
                            <div class="baris-1">{{ $usd($asset['avg_buy_price_usdt']) }}</div>
                            <div class="baris-2">{{ $usd($asset['current_price_usdt']) }}</div>
                        </td>
                        <td class="kol-super">
                            @if($dinilai)
                                <div class="baris-1">Rp {{ number_format($asset['cost_idr'], 0, ',', '.') }}</div>
                                <div class="baris-2 {{ $asset['pnl_idr'] >= 0 ? 'text-success' : 'text-danger' }}" style="font-weight: 600;">
                                    <span class="nilai-rp">{{ $asset['pnl_idr'] >= 0 ? '+' : '-' }}Rp {{ number_format(abs($asset['pnl_idr']), 0, ',', '.') }}</span>
                                    <span class="pnl-persen">({{ $asset['pnl_percent'] >= 0 ? '+' : '' }}{{ number_format($asset['pnl_percent'], 1) }}%)</span>
                                </div>
                            @else
                                <span style="color: var(--text-muted);">&mdash;</span>
                            @endif
                        </td>
                        <td class="kol-full" data-label="Sumber" style="font-size: 0.75rem; color: var(--text-muted); font-style: italic;">
                            {{ $asset['source'] }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="14" class="kartu-kosong" style="text-align: center; color: var(--text-muted); padding: 3rem 0;">
                            Tidak ada saldo aset aktif di portfolio saat ini.
                        </td>
                    </tr>
                @endforelse
                @if(count($assets) > 0 && ($aset_tampil_jumlah ?? 0) === 0)
                    <tr class="baris-semua-tersembunyi">
                        <td colspan="14" class="kartu-kosong" style="text-align: center; color: var(--text-muted); padding: 3rem 0;">
                            Semua aset bernilai di bawah ${{ number_format($batas_aset_kecil_usdt, 0) }} atau tanpa harga pasar, jadi disembunyikan.
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
    @php $jumlahTersembunyi = ($aset_kecil_jumlah ?? 0) + count($aset_tanpa_harga ?? []); @endphp
    @if($jumlahTersembunyi > 0)
        <div class="info-tersembunyi" style="margin-top: 0.85rem; font-size: 0.8rem; color: var(--text-muted); display: flex; gap: 0.5rem 0.75rem; align-items: center; flex-wrap: wrap;">
            <span>
                @if(($aset_kecil_jumlah ?? 0) > 0)
                    {{ $aset_kecil_jumlah }} aset di bawah ${{ number_format($batas_aset_kecil_usdt, 0) }}
                    (total ${{ number_format($aset_kecil_nilai_usdt, 2) }})
                @endif
                @if(!empty($aset_tanpa_harga))
                    {{ ($aset_kecil_jumlah ?? 0) > 0 ? '+' : '' }} {{ count($aset_tanpa_harga) }} tanpa harga pasar
                @endif
                <span class="teks-status">disembunyikan</span>. Nilainya tetap dihitung di total portofolio.
            </span>
            <button type="button" id="toggleSemuaAset" class="btn btn-secondary btn-sm">Tampilkan semua ({{ $jumlahTersembunyi }})</button>
        </div>
    @endif
</div>
@endsection

@section('scripts')
<style>
    .judul-baris { display: flex; align-items: center; gap: 0.5rem 1rem; flex-wrap: wrap; }
    .mode-tabel { display: inline-flex; gap: 0.25rem; }
    .stats-porto .lbl-pendek { display: none; }

    /* ---- Super Simple: bagian atas diringkas supaya tabel langsung terlihat ---- */
    body.porto-super .page-header { gap: 0.6rem; margin-bottom: 1rem; }
    body.porto-super .page-title p { display: none; }
    body.porto-super .page-title h1 { font-size: 1.35rem; }
    body.porto-super .header-actions { gap: 0.35rem !important; }
    body.porto-super .header-actions .badge { font-size: 0.65rem !important; padding: 0.2rem 0.45rem; }
    body.porto-super .stats-porto { grid-template-columns: repeat(3, minmax(0, 1fr)) !important; gap: 0.5rem; margin-bottom: 1rem; }
    body.porto-super .stats-porto .glass-card { padding: 0.65rem 0.7rem; min-width: 0; }
    body.porto-super .stats-porto .card-title { margin-bottom: 0.3rem; font-size: 0.72rem; }
    body.porto-super .stats-porto .card-title svg,
    body.porto-super .stats-porto .card-title .badge,
    body.porto-super .stats-porto .lbl-panjang { display: none; }
    body.porto-super .stats-porto .lbl-pendek { display: inline; }
    body.porto-super .stats-porto .stat-value { font-size: 1rem !important; margin-bottom: 0.15rem; letter-spacing: 0; white-space: nowrap; }
    body.porto-super .stats-porto .stat-value .pnl-pct { display: block; font-size: 0.72rem !important; margin-left: 0 !important; }
    body.porto-super .stats-porto .stat-desc { font-size: 0.68rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mode-tabel .btn { padding: 0.35rem 0.85rem; font-size: 0.8rem; }
    #assetTable.mode-simple .kol-full { display: none !important; }
    #assetTable:not(.mode-super) .kol-super { display: none !important; }
    #assetTable.mode-super .kol-full,
    #assetTable.mode-super .kol-biasa { display: none !important; }
    /* Super Simple: 3 kolom ringkas, tiap sel 2 baris, muat di ponsel tanpa geser */
    #assetTable.mode-super th,
    #assetTable.mode-super td { padding: 0.6rem 0.4rem; vertical-align: middle; }
    #assetTable.mode-super th:first-child,
    #assetTable.mode-super td:first-child { padding-left: 0; }
    #assetTable.mode-super th:last-child,
    #assetTable.mode-super td:last-child { padding-right: 0; }
    #assetTable.mode-super th.kol-super { text-align: right; line-height: 1.3; }
    #assetTable.mode-super th .sub-th { opacity: 0.7; }
    #assetTable.mode-super td.kol-super { font-family: monospace; white-space: nowrap; text-align: right; font-size: 0.82rem; }
    #assetTable.mode-super td.kol-super .baris-2 { margin-top: 0.2rem; }
    #assetTable.mode-super td.kol-super .pnl-persen { white-space: nowrap; }
    #assetTable.mode-super { width: 100%; table-layout: auto; }
    @media (max-width: 400px) {
        #assetTable.mode-super td.kol-super { font-size: 0.74rem; white-space: normal; }
        #assetTable.mode-super td.kol-super .baris-1,
        #assetTable.mode-super td.kol-super .nilai-rp { white-space: nowrap; }
        #assetTable.mode-super td.kartu-judul { font-size: 0.9rem !important; }
    }
    #assetTable.mode-super td.kol-super > div:not(.text-success):not(.text-danger).baris-2 { color: var(--text-muted); }
    #assetTable.mode-super td.kartu-judul { font-size: 1rem !important; }
    .glass-card.mode-super .sort-mobile { display: none !important; }
    #assetTable:not(.tampil-semua) tr.aset-tersembunyi,
    #assetTable.tampil-semua tr.baris-semua-tersembunyi { display: none !important; }
    #assetTable.tampil-semua tr.aset-tersembunyi { opacity: 0.6; }
    @media (max-width: 768px) {
        /* Sumber (kolom terakhir) disembunyikan, jadi PNL (%) yang jadi baris terakhir kartu */
        #assetTable.mode-simple tr td:nth-last-child(2) { border-bottom: none; }
    }
</style>
<script>
    // ---- Mode tampilan tabel: Simple / Full ----
    document.addEventListener("DOMContentLoaded", function() {
        const table = document.getElementById("assetTable");
        const buttons = [...document.querySelectorAll(".mode-tabel [data-mode]")];
        const KEY = "portfolioTableMode";
        let mode = null;
        try { mode = localStorage.getItem(KEY); } catch (e) {}
        if (!["super", "simple", "full"].includes(mode)) {
            mode = window.matchMedia("(max-width: 768px)").matches ? "simple" : "full";
        }

        function setMode(m) {
            mode = m;
            table.classList.toggle("mode-simple", m === "simple");
            table.classList.toggle("mode-super", m === "super");
            // Super Simple selalu tabel ringkas, tidak jadi kartu di ponsel
            table.classList.toggle("table-kartu", m !== "super");
            table.closest(".glass-card").classList.toggle("mode-super", m === "super");
            document.body.classList.toggle("porto-super", m === "super");
            buttons.forEach(b => {
                const aktif = b.dataset.mode === m;
                b.classList.toggle("btn-primary", aktif);
                b.classList.toggle("btn-secondary", !aktif);
                b.setAttribute("aria-pressed", aktif);
            });
        }

        buttons.forEach(b => b.addEventListener("click", () => {
            setMode(b.dataset.mode);
            try { localStorage.setItem(KEY, mode); } catch (e) {}
        }));
        setMode(mode);
    });

    // ---- Tampilkan/sembunyikan aset kecil & tanpa harga (bawaan: disembunyikan) ----
    document.addEventListener("DOMContentLoaded", function() {
        const btn = document.getElementById("toggleSemuaAset");
        if (!btn) return;
        const table = document.getElementById("assetTable");
        const status = document.querySelector(".info-tersembunyi .teks-status");
        const labelTampil = btn.textContent;
        btn.addEventListener("click", () => {
            const semua = table.classList.toggle("tampil-semua");
            btn.textContent = semua ? "Sembunyikan aset kecil" : labelTampil;
            if (status) status.textContent = semua ? "ditampilkan (redup)" : "disembunyikan";
        });
    });

    // ---- Sorting tabel aset (sisi klien) ----
    document.addEventListener("DOMContentLoaded", function() {
        const table = document.getElementById("assetTable");
        const tbody = table.querySelector("tbody");
        const headers = [...table.querySelectorAll("th.th-sort")];
        const keySelect = document.getElementById("sortKeySelect");
        const dirBtn = document.getElementById("sortDirBtn");
        const STORAGE_KEY = "portfolioAssetSort";

        // Hanya baris aset; baris "data kosong" tidak punya data-sort-*
        const rows = [...tbody.querySelectorAll("tr[data-sort-asset]")];
        rows.forEach((tr, i) => tr.dataset.urutanAsli = i);

        // Kolom Super Simple memakai kunci yang sama; cukup satu opsi per kunci
        headers.filter(th => !th.classList.contains("kol-super")).forEach(th => {
            keySelect.add(new Option(th.textContent.trim(), th.dataset.sortKey));
        });

        let state = { key: "", dir: "desc" };
        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || "null");
            if (saved && headers.some(th => th.dataset.sortKey === saved.key)) state = saved;
        } catch (e) {}

        function apply() {
            const th = headers.find(h => h.dataset.sortKey === state.key);
            const isText = th && th.dataset.sortType === "text";
            const attr = "sort" + state.key.charAt(0).toUpperCase() + state.key.slice(1);
            const arah = state.dir === "asc" ? 1 : -1;

            const sorted = [...rows].sort((a, b) => {
                if (!th) return a.dataset.urutanAsli - b.dataset.urutanAsli;

                const va = a.dataset[attr] ?? "";
                const vb = b.dataset[attr] ?? "";
                // Nilai kosong (aset tak bisa dinilai) selalu di bawah, apa pun arahnya
                if (va === "" && vb === "") return 0;
                if (va === "") return 1;
                if (vb === "") return -1;

                return (isText
                    ? va.localeCompare(vb, "id", { sensitivity: "base" })
                    : parseFloat(va) - parseFloat(vb)) * arah;
            });
            sorted.forEach(tr => tbody.appendChild(tr));

            headers.forEach(h => {
                h.classList.remove("sort-asc", "sort-desc");
                if (th && h.dataset.sortKey === state.key) h.classList.add(state.dir === "asc" ? "sort-asc" : "sort-desc");
            });
            keySelect.value = state.key;
            dirBtn.textContent = state.dir === "asc" ? "↑" : "↓";
            dirBtn.disabled = !th;

            try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
        }

        headers.forEach(th => {
            th.addEventListener("click", () => {
                const key = th.dataset.sortKey;
                if (state.key === key) {
                    state.dir = state.dir === "asc" ? "desc" : "asc";
                } else {
                    // Teks mulai A→Z, angka mulai dari yang terbesar
                    state = { key, dir: th.dataset.sortType === "text" ? "asc" : "desc" };
                }
                apply();
            });
        });

        keySelect.addEventListener("change", () => {
            const th = headers.find(h => h.dataset.sortKey === keySelect.value);
            state = { key: keySelect.value, dir: th && th.dataset.sortType === "text" ? "asc" : "desc" };
            apply();
        });

        dirBtn.addEventListener("click", () => {
            state.dir = state.dir === "asc" ? "desc" : "asc";
            apply();
        });

        apply();
    });

    document.addEventListener("DOMContentLoaded", function() {
        let timeLeft = 120; // 2 minutes (120 seconds)
        const timerSpan = document.getElementById("countdownTimer");
        const badgeSpan = document.getElementById("countdownBadge");
        
        if (timerSpan && badgeSpan) {
            badgeSpan.style.display = "inline-flex";
            const interval = setInterval(() => {
                timeLeft--;
                timerSpan.textContent = timeLeft;
                if (timeLeft <= 0) {
                    clearInterval(interval);
                    window.location.reload();
                }
            }, 1000);
        }
    });
</script>
@endsection
