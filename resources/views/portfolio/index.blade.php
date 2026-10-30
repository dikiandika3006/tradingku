@extends('layouts.app')

@section('title', 'Portofolio Detail')

@section('content')
<div class="page-header">
    <div class="page-title">
        <h1>Portofolio Detail</h1>
        <p>Pantau saldo kepemilikan aset, harga rata-rata beli (average price), serta keuntungan/kerugian belum terealisasi</p>
    </div>
    <div class="header-actions" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
        <span id="countdownBadge" class="badge badge-neutral" style="font-family: monospace; font-size: 0.75rem; background: rgba(255, 255, 255, 0.08); border: 1px solid var(--border-light); display: none;">
            Refresh: <span id="countdownTimer">120</span>s
        </span>
        <span class="badge {{ $is_live ? 'badge-success' : 'badge-danger' }}">
            {{ $is_live ? 'Koneksi API Tokocrypto: LIVE' : 'Mode Fallback: Database Trade Logs' }}
        </span>
    </div>
</div>

<!-- Portfolio Performance Boxes -->
<div class="stats-grid">
    <!-- Card 1: Valuation -->
    <div class="glass-card">
        <div class="card-title">
            <span>Nilai Portofolio Saat Ini</span>
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="stat-value text-success">${{ number_format($total_valuation_usdt, 2) }}</div>
        <div class="stat-desc">≈ Rp {{ number_format($total_valuation_idr, 0, ',', '.') }}</div>
    </div>

    <!-- Card 2: Cost Basis -->
    <div class="glass-card">
        <div class="card-title">
            <span>Modal Investasi (Cost Basis)</span>
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/></svg>
        </div>
        <div class="stat-value" style="color: var(--text-main);">${{ number_format($total_cost_usdt, 2) }}</div>
        <div class="stat-desc">≈ Rp {{ number_format($total_cost_idr, 0, ',', '.') }}</div>
    </div>

    <!-- Card 3: Unrealized Profit/Loss -->
    <div class="glass-card">
        <div class="card-title">
            <span>PNL Belum Terealisasi (Unrealized PNL)</span>
            @if($total_pnl_usdt >= 0)
                <span class="badge badge-success">PROFIT</span>
            @else
                <span class="badge badge-danger">LOSS</span>
            @endif
        </div>
        <div class="stat-value {{ $total_pnl_usdt >= 0 ? 'text-success' : 'text-danger' }}">
            {{ $total_pnl_usdt >= 0 ? '+' : '-' }}${{ number_format(abs($total_pnl_usdt), 2) }}
            <span style="font-size: 1.1rem; font-weight: 600; margin-left: 0.25rem;">
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
                    <th class="th-sort" data-sort-key="saldo">Jumlah Saldo</th>
                    <th class="th-sort" data-sort-key="durasi">Durasi</th>
                    <th class="th-sort" data-sort-key="avg">Harga Rata-rata Beli</th>
                    <th class="th-sort" data-sort-key="harga">Harga Pasar</th>
                    <th class="th-sort" data-sort-key="modal">Modal (Cost Basis)</th>
                    <th class="th-sort" data-sort-key="nilai">Nilai Saat Ini</th>
                    <th class="th-sort" data-sort-key="pnl">PNL Belum Terealisasi</th>
                    <th class="th-sort" data-sort-key="pnlpct">PNL (%)</th>
                    <th class="th-sort" data-sort-key="sumber" data-sort-type="text">Sumber</th>
                </tr>
            </thead>
            <tbody>
                @forelse($assets as $asset)
                    @php $dinilai = $asset['can_be_valued'] ?? true; @endphp
                    {{-- Nilai mentah untuk sorting; kosong = tidak bisa dinilai, selalu ditaruh paling bawah --}}
                    <tr data-sort-asset="{{ $asset['asset'] }}"
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
                        <td data-label="Jumlah Saldo" style="font-family: monospace; font-weight: 600;">
                            {{ number_format($asset['total'], 6) }}
                            <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 400; margin-top: 0.15rem;">
                                Tersedia: {{ number_format($asset['free'], 4) }} | Order: {{ number_format($asset['locked'], 4) }}
                            </div>
                        </td>
                        <td data-label="Durasi">
                            <span class="badge" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: var(--text-main); font-weight: 600; padding: 0.35rem 0.65rem; border-radius: 6px; display: inline-block;">
                                {{ $asset['duration_text'] }}
                            </span>
                            @if($asset['first_buy_date'])
                                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.25rem;">
                                    Sejak: {{ $asset['first_buy_date'] }}
                                </div>
                            @endif
                        </td>
                        <td data-label="Rata-rata Beli" style="font-family: monospace;">
                            ${{ number_format($asset['avg_buy_price_usdt'], 4) }}
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;">
                                Rp {{ number_format($asset['avg_buy_price_idr'], 0, ',', '.') }}
                            </div>
                        </td>
                        <td data-label="Harga Pasar" style="font-family: monospace; font-weight: 500;">
                            ${{ number_format($asset['current_price_usdt'], 4) }}
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;">
                                Rp {{ number_format($asset['current_price_idr'], 0, ',', '.') }}
                            </div>
                        </td>
                        <td data-label="Modal" style="font-family: monospace;">
                            @if($asset['can_be_valued'] ?? true)
                                ${{ number_format($asset['cost_usdt'], 2) }}
                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;">
                                    Rp {{ number_format($asset['cost_idr'], 0, ',', '.') }}
                                </div>
                            @else
                                <span style="color: var(--text-muted);">&mdash;</span>
                            @endif
                        </td>
                        <td data-label="Nilai Saat Ini" style="font-family: monospace; font-weight: 600;">
                            ${{ number_format($asset['value_usdt'], 2) }}
                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.15rem;">
                                Rp {{ number_format($asset['value_idr'], 0, ',', '.') }}
                            </div>
                        </td>
                        <td data-label="PNL Belum Terealisasi" style="font-family: monospace; font-weight: 600;" class="{{ ($asset['can_be_valued'] ?? true) ? ($asset['pnl_usdt'] >= 0 ? 'text-success' : 'text-danger') : '' }}">
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
                        <td data-label="PNL (%)" style="font-family: monospace; font-weight: 700;" class="{{ ($asset['can_be_valued'] ?? true) ? ($asset['pnl_percent'] >= 0 ? 'text-success' : 'text-danger') : '' }}">
                            @if($asset['can_be_valued'] ?? true)
                                {{ $asset['pnl_percent'] >= 0 ? '+' : '' }}{{ number_format($asset['pnl_percent'], 2) }}%
                            @else
                                <span style="color: var(--text-muted);" title="Tidak punya harga pasar, jadi tidak bisa dinilai">tidak dinilai</span>
                            @endif
                        </td>
                        <td data-label="Sumber" style="font-size: 0.75rem; color: var(--text-muted); font-style: italic;">
                            {{ $asset['source'] }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="kartu-kosong" style="text-align: center; color: var(--text-muted); padding: 3rem 0;">
                            Tidak ada saldo aset aktif di portfolio saat ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@section('scripts')
<script>
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

        headers.forEach(th => {
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
                if (h === th) h.classList.add(state.dir === "asc" ? "sort-asc" : "sort-desc");
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
