@extends('layouts.app')

@section('title', 'Master Investor')

@php
    $rp = fn ($v) => 'Rp ' . number_format($v, 0, ',', '.');
    $tgl = fn ($d) => $d ? $d->locale('id')->translatedFormat('d M Y') : '-';
    // Warna per investor, dipakai bersama oleh bar kepemilikan dan grafik donat.
    $palet = ['#9d4edd', '#00f2fe', '#00e676', '#ffb703', '#f72585', '#90be6d', '#4361ee', '#fb8500'];
    $warna = $investors->values()->mapWithKeys(fn ($inv, $i) => [$inv->id => $palet[$i % count($palet)]]);
    $tipeBadge = ['initial_capital' => 'badge-neutral', 'top_up' => 'badge-success', 'withdrawal' => 'badge-danger'];
    $chartData = $summary->filter(fn ($r) => $r['total'] > 0)->values()->map(fn ($r) => [
        'label' => $r['investor']->name,
        'value' => $r['total'],
        'color' => $warna[$r['investor']->id],
    ]);
@endphp

@section('content')
<div class="page-header">
    <div class="page-title">
        <h1>Master Investor</h1>
        <p>Dana masing-masing investor, persentase kepemilikan, dan riwayat setoran/penarikan</p>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success mb-4">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger mb-4">{{ session('error') }}</div>
@endif
@if($errors->any())
    <div class="alert alert-danger mb-4">{{ $errors->first() }}</div>
@endif

<div class="stats-grid">
    <div class="glass-card">
        <div class="card-title"><span>Total Dana Investor</span></div>
        <div class="stat-value text-success">{{ $rp($stats['total_dana']) }}</div>
        <div class="stat-desc">Akumulasi setoran bersih seluruh investor</div>
    </div>
    <div class="glass-card">
        <div class="card-title"><span>Investor Aktif</span></div>
        <div class="stat-value">{{ $stats['investor_aktif'] }} <span style="font-size: 1rem; color: var(--text-muted); font-weight: 600;">/ {{ $investors->count() }}</span></div>
        <div class="stat-desc">{{ $stats['jumlah_tx'] }} transaksi tercatat</div>
    </div>
    <div class="glass-card">
        <div class="card-title"><span>Setoran Bersih Bulan Ini</span></div>
        <div class="stat-value {{ $stats['setoran_bulan'] < 0 ? 'text-danger' : '' }}">{{ $rp($stats['setoran_bulan']) }}</div>
        <div class="stat-desc">{{ now()->locale('id')->translatedFormat('F Y') }}</div>
    </div>
</div>

<!-- Master -->
<div class="mb-6">
    <div class="glass-card">
        <div class="card-title">
            <span>Daftar Investor & Kepemilikan</span>
            <button type="button" class="btn btn-secondary btn-sm" id="btnTambahInvestor">+ Investor</button>
        </div>
        <div class="table-responsive">
            <table class="custom-table table-kartu">
                <thead>
                    <tr>
                        <th>Investor</th>
                        <th>Tgl Gabung</th>
                        <th style="text-align: right;">Modal Awal</th>
                        <th style="text-align: right;">Top Up</th>
                        <th style="text-align: right;">Penarikan</th>
                        <th style="text-align: right;">Total Dana</th>
                        <th style="min-width: 140px;">Kepemilikan</th>
                        <th>Status</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($summary as $row)
                        @php $inv = $row['investor']; @endphp
                        <tr>
                            <td class="kartu-judul">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span style="width: 10px; height: 10px; border-radius: 50%; background: {{ $warna[$inv->id] }}; flex-shrink: 0;"></span>
                                    <div>
                                        <div style="font-weight: 700;">{{ $inv->name }}</div>
                                        <div style="font-size: 0.72rem; color: var(--text-muted); font-family: monospace;">{{ $inv->code }}</div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Tgl Gabung">{{ $tgl($inv->join_date) }}</td>
                            <td data-label="Modal Awal" style="text-align: right; font-family: monospace;">{{ $rp($row['modal_awal']) }}</td>
                            <td data-label="Top Up" style="text-align: right; font-family: monospace;">{{ $rp($row['top_up']) }}</td>
                            <td data-label="Penarikan" style="text-align: right; font-family: monospace; {{ $row['penarikan'] > 0 ? 'color: var(--color-danger);' : 'color: var(--text-muted);' }}">
                                {{ $row['penarikan'] > 0 ? '-' . $rp($row['penarikan']) : '-' }}
                            </td>
                            <td data-label="Total Dana" style="text-align: right; font-family: monospace; font-weight: 700;">{{ $rp($row['total']) }}</td>
                            <td data-label="Kepemilikan">
                                <div style="font-weight: 700; font-family: monospace;">{{ number_format($row['kepemilikan'], 2) }}%</div>
                                <div style="height: 6px; background: rgba(var(--tint-rgb), 0.06); border-radius: 3px; margin-top: 0.3rem; overflow: hidden;">
                                    <div style="height: 100%; width: {{ min(100, $row['kepemilikan']) }}%; background: {{ $warna[$inv->id] }};"></div>
                                </div>
                            </td>
                            <td data-label="Status">
                                <span class="badge {{ $inv->status === 'aktif' ? 'badge-success' : 'badge-neutral' }}">{{ $inv->status }}</span>
                            </td>
                            <td data-label="Aksi" style="text-align: right;">
                                <div style="display: inline-flex; gap: 0.75rem; align-items: center;">
                                    <a href="{{ route('investor.index', ['investor' => $inv->id]) }}#riwayat" title="Lihat riwayat" style="color: var(--text-muted); display: flex;">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </a>
                                    <button type="button" class="btn-edit-investor" title="Edit investor"
                                            data-id="{{ $inv->id }}"
                                            data-code="{{ $inv->code }}"
                                            data-name="{{ $inv->name }}"
                                            data-join="{{ $inv->join_date->format('Y-m-d') }}"
                                            data-status="{{ $inv->status }}"
                                            data-notes="{{ $inv->notes }}"
                                            style="background: none; border: none; color: var(--color-secondary); cursor: pointer; display: flex;">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    @if($row['jumlah_tx'] === 0)
                                        <form action="{{ route('investor.destroy', $inv->id) }}" method="POST" onsubmit="return confirm('Hapus investor {{ $inv->name }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" title="Hapus investor" style="background: none; border: none; color: var(--tc-ef4444); cursor: pointer; display: flex;">
                                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="kartu-kosong" style="text-align: center; color: var(--text-muted); padding: 3rem 0;">Belum ada investor.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if($summary->isNotEmpty())
                    <tfoot>
                        <tr style="font-weight: 700; border-top: 1px solid var(--border-light);">
                            <td class="kartu-judul">Total</td>
                            <td data-label="" class="sembunyi-mobile"></td>
                            <td data-label="Modal Awal" style="text-align: right; font-family: monospace;">{{ $rp($summary->sum('modal_awal')) }}</td>
                            <td data-label="Top Up" style="text-align: right; font-family: monospace;">{{ $rp($summary->sum('top_up')) }}</td>
                            <td data-label="Penarikan" style="text-align: right; font-family: monospace;">{{ $summary->sum('penarikan') > 0 ? '-' . $rp($summary->sum('penarikan')) : '-' }}</td>
                            <td data-label="Total Dana" style="text-align: right; font-family: monospace;" class="text-success">{{ $rp($stats['total_dana']) }}</td>
                            <td data-label="Kepemilikan" style="font-family: monospace;">{{ $stats['total_dana'] > 0 ? '100.00%' : '-' }}</td>
                            <td colspan="2" class="sembunyi-mobile"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>

<!-- Form + riwayat -->
<div class="dashboard-row investor-row" id="riwayat">
    <div style="display: grid; gap: 1.5rem; height: fit-content; min-width: 0;">
        <!-- Komposisi -->
        <div class="glass-card">
            <div class="card-title">Komposisi Dana</div>
            @if($stats['total_dana'] > 0)
                <div style="position: relative; max-width: 220px; margin: 0 auto;">
                    <canvas id="chartKomposisi"></canvas>
                </div>
                <div style="margin-top: 1.25rem; display: grid; gap: 0.5rem;">
                    @foreach($summary->sortByDesc('total') as $row)
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; gap: 0.5rem;">
                            <span style="display: flex; align-items: center; gap: 0.5rem; min-width: 0;">
                                <span style="width: 10px; height: 10px; border-radius: 50%; background: {{ $warna[$row['investor']->id] }}; flex-shrink: 0;"></span>
                                {{ $row['investor']->name }}
                            </span>
                            <span style="font-family: monospace; color: var(--text-muted); white-space: nowrap;">{{ $rp($row['total']) }} · {{ number_format($row['kepemilikan'], 2) }}%</span>
                        </div>
                    @endforeach
                </div>
            @else
                <p style="color: var(--text-muted); text-align: center; padding: 2rem 0;">Belum ada dana tercatat.</p>
            @endif
        </div>

        <!-- Form transaksi -->
        <div class="glass-card">
            <div id="txFormTitle" class="card-title">Catat Setoran / Penarikan</div>
            <form action="{{ route('investor.transaction.store') }}" method="POST" id="txForm">
                @csrf
                <div id="txMethod"></div>
                <div class="form-group">
                    <label for="tx_investor" class="form-label">Investor</label>
                    <select name="investor_id" id="tx_investor" class="form-control" required>
                        <option value="">-- Pilih investor --</option>
                        @foreach($investors as $inv)
                            <option value="{{ $inv->id }}" {{ (string) old('investor_id', $filterInvestor) === (string) $inv->id ? 'selected' : '' }}>
                                {{ $inv->code }} — {{ $inv->name }}{{ $inv->status !== 'aktif' ? ' (nonaktif)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-grid-2col">
                    <div class="form-group">
                        <label for="tx_date" class="form-label">Tanggal</label>
                        <input type="date" name="date" id="tx_date" class="form-control" value="{{ old('date', now()->format('Y-m-d')) }}" required>
                    </div>
                    <div class="form-group">
                        <label for="tx_type" class="form-label">Jenis</label>
                        <select name="type" id="tx_type" class="form-control" required>
                            @foreach($types as $key => $label)
                                <option value="{{ $key }}" {{ old('type', 'top_up') === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="tx_amount" class="form-label">Nominal (Rp)</label>
                    <input type="number" name="amount" id="tx_amount" class="form-control" min="1" step="1" placeholder="contoh: 500000" value="{{ old('amount') }}" required>
                    <small id="txAmountPreview" style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-top: 0.25rem;"></small>
                </div>
                <div class="form-group">
                    <label for="tx_notes" class="form-label">Catatan (opsional)</label>
                    <input type="text" name="notes" id="tx_notes" class="form-control" maxlength="500" value="{{ old('notes') }}">
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <button type="submit" id="txSubmit" class="btn btn-primary" style="flex-grow: 1;">Simpan Transaksi</button>
                    <button type="button" id="txCancel" class="btn btn-secondary" style="display: none;">Batal</button>
                </div>
            </form>
        </div>

        <!-- Form investor -->
        <div class="glass-card" id="investorFormCard">
            <div id="invFormTitle" class="card-title">Tambah Investor Baru</div>
            <form action="{{ route('investor.store') }}" method="POST" id="invForm">
                @csrf
                <div id="invMethod"></div>
                <div class="form-group">
                    <label class="form-label">Kode</label>
                    <input type="text" id="inv_code" class="form-control" value="{{ $nextCode }}" disabled>
                </div>
                <div class="form-group">
                    <label for="inv_name" class="form-label">Nama Investor</label>
                    <input type="text" name="name" id="inv_name" class="form-control" required>
                </div>
                <div class="form-grid-2col">
                    <div class="form-group">
                        <label for="inv_join" class="form-label">Tanggal Gabung</label>
                        <input type="date" name="join_date" id="inv_join" class="form-control" value="{{ now()->format('Y-m-d') }}" required>
                    </div>
                    <div class="form-group">
                        <label for="inv_status" class="form-label">Status</label>
                        <select name="status" id="inv_status" class="form-control">
                            <option value="aktif">Aktif</option>
                            <option value="nonaktif">Nonaktif</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="inv_notes" class="form-label">Catatan (opsional)</label>
                    <input type="text" name="notes" id="inv_notes" class="form-control" maxlength="1000">
                </div>
                <small style="color: var(--text-muted); font-size: 0.75rem; display: block; margin-bottom: 1rem;">
                    Modal awal dicatat lewat form transaksi dengan jenis "Initial Capital".
                </small>
                <div style="display: flex; gap: 0.5rem;">
                    <button type="submit" id="invSubmit" class="btn btn-primary" style="flex-grow: 1;">Tambah Investor</button>
                    <button type="button" id="invCancel" class="btn btn-secondary" style="display: none;">Batal</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Riwayat -->
    <div class="glass-card">
        <div class="card-title" style="flex-wrap: wrap; gap: 0.75rem;">
            <span>Riwayat Transaksi Investor</span>
            <form method="GET" action="{{ route('investor.index') }}#riwayat" style="margin: 0;">
                <select name="investor" class="form-control" onchange="this.form.submit()" style="padding: 0.45rem 0.75rem; font-size: 0.85rem; width: auto;">
                    <option value="">Semua investor</option>
                    @foreach($investors as $inv)
                        <option value="{{ $inv->id }}" {{ $filterInvestor === $inv->id ? 'selected' : '' }}>{{ $inv->name }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        <p style="color: var(--text-muted); font-size: 0.78rem; margin: -0.5rem 0 1rem;">
            "Saldo Investor" dan "Kepemilikan" adalah posisi tepat setelah transaksi tersebut.
        </p>
        <div class="table-responsive">
            <table class="custom-table table-kartu">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Investor</th>
                        <th>Jenis</th>
                        <th style="text-align: right;">Nominal</th>
                        <th style="text-align: right;">Saldo Investor</th>
                        <th style="text-align: right;">Total Dana</th>
                        <th style="text-align: right;">Kepemilikan</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($history as $h)
                        @php $tx = $h['tx']; @endphp
                        <tr>
                            <td class="kartu-judul">
                                <div style="font-weight: 600;">{{ $tgl($tx->date) }}</div>
                                @if($tx->notes)
                                    <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 0.15rem;">{{ $tx->notes }}</div>
                                @endif
                            </td>
                            <td data-label="Investor">
                                <span style="display: inline-flex; align-items: center; gap: 0.4rem;">
                                    <span style="width: 8px; height: 8px; border-radius: 50%; background: {{ $warna[$tx->investor_id] ?? '#888' }};"></span>
                                    {{ $tx->investor->name }}
                                </span>
                            </td>
                            <td data-label="Jenis"><span class="badge {{ $tipeBadge[$tx->type] ?? 'badge-neutral' }}">{{ $tx->typeLabel() }}</span></td>
                            <td data-label="Nominal" style="text-align: right; font-family: monospace; font-weight: 600;" class="{{ $tx->type === 'withdrawal' ? 'text-danger' : 'text-success' }}">
                                {{ $tx->type === 'withdrawal' ? '-' : '+' }}{{ $rp($tx->amount) }}
                            </td>
                            <td data-label="Saldo Investor" style="text-align: right; font-family: monospace;">{{ $rp($h['saldo']) }}</td>
                            <td data-label="Total Dana" style="text-align: right; font-family: monospace; color: var(--text-muted);">{{ $rp($h['total_dana']) }}</td>
                            <td data-label="Kepemilikan" style="text-align: right; font-family: monospace; font-weight: 600;">{{ number_format($h['kepemilikan'], 2) }}%</td>
                            <td data-label="Aksi" style="text-align: right;">
                                <div style="display: inline-flex; gap: 0.75rem; align-items: center;">
                                    <button type="button" class="btn-edit-tx" title="Edit transaksi"
                                            data-id="{{ $tx->id }}"
                                            data-investor="{{ $tx->investor_id }}"
                                            data-date="{{ $tx->date->format('Y-m-d') }}"
                                            data-type="{{ $tx->type }}"
                                            data-amount="{{ (int) round($tx->amount) }}"
                                            data-notes="{{ $tx->notes }}"
                                            style="background: none; border: none; color: var(--color-secondary); cursor: pointer; display: flex;">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    <form action="{{ route('investor.transaction.destroy', $tx->id) }}" method="POST" onsubmit="return confirm('Hapus transaksi {{ $tx->investor->name }} {{ $rp($tx->amount) }} tanggal {{ $tgl($tx->date) }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" title="Hapus transaksi" style="background: none; border: none; color: var(--tc-ef4444); cursor: pointer; display: flex;">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="kartu-kosong" style="text-align: center; color: var(--text-muted); padding: 3rem 0;">Belum ada transaksi.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Rekap bulanan -->
@if(!empty($rekapBulanan))
<div class="glass-card mb-6">
    <div class="card-title">Rekap Setoran per Bulan</div>
    <div class="table-responsive">
        <table class="custom-table" style="min-width: {{ 320 + $investors->count() * 130 }}px;">
            <thead>
                <tr>
                    <th>Bulan</th>
                    @foreach($investors as $inv)
                        <th style="text-align: right;">{{ $inv->name }}</th>
                    @endforeach
                    <th style="text-align: right;">Total Bulan</th>
                    <th style="text-align: right;">Dana Kumulatif</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rekapBulanan as $r)
                    <tr>
                        <td style="font-weight: 600; white-space: nowrap;">{{ $r['label'] }}</td>
                        @foreach($investors as $inv)
                            @php $v = $r['per_investor'][$inv->id] ?? 0; @endphp
                            <td style="text-align: right; font-family: monospace; {{ $v == 0 ? 'color: var(--text-muted);' : ($v < 0 ? 'color: var(--color-danger);' : '') }}">
                                {{ $v == 0 ? '-' : $rp($v) }}
                            </td>
                        @endforeach
                        <td style="text-align: right; font-family: monospace; font-weight: 600;">{{ $rp($r['total']) }}</td>
                        <td style="text-align: right; font-family: monospace; font-weight: 700;" class="text-success">{{ $rp($r['kumulatif']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<style>
    .investor-row { grid-template-columns: 1fr 2.4fr; }
    .custom-table tfoot td { background: rgba(var(--tint-rgb), 0.02); }
    @media (max-width: 768px) {
        .custom-table.table-kartu tfoot { display: block; margin-top: 0.9rem; }
        .custom-table.table-kartu td.sembunyi-mobile { display: none; }
    }
</style>
@endsection

@section('scripts')
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const rupiah = v => "Rp " + Number(v || 0).toLocaleString("id-ID");

        // ---- Grafik komposisi ----
        const canvas = document.getElementById("chartKomposisi");
        if (canvas && window.Chart) {
            const data = @json($chartData);
            new Chart(canvas, {
                type: "doughnut",
                data: {
                    labels: data.map(d => d.label),
                    datasets: [{
                        data: data.map(d => d.value),
                        backgroundColor: data.map(d => d.color),
                        borderColor: "#0d0f28",
                        borderWidth: 2,
                    }],
                },
                options: {
                    cutout: "65%",
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => {
                                    const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    return ` ${ctx.label}: ${rupiah(ctx.raw)} (${(ctx.raw / total * 100).toFixed(2)}%)`;
                                },
                            },
                        },
                    },
                },
            });
        }

        // ---- Form transaksi: tambah / edit ----
        const txForm = document.getElementById("txForm");
        const txTitle = document.getElementById("txFormTitle");
        const txMethod = document.getElementById("txMethod");
        const txSubmit = document.getElementById("txSubmit");
        const txCancel = document.getElementById("txCancel");
        const txAmount = document.getElementById("tx_amount");
        const txPreview = document.getElementById("txAmountPreview");

        const updatePreview = () => { txPreview.textContent = txAmount.value ? rupiah(txAmount.value) : ""; };
        txAmount.addEventListener("input", updatePreview);
        updatePreview();

        document.querySelectorAll(".btn-edit-tx").forEach(btn => {
            btn.addEventListener("click", () => {
                const d = btn.dataset;
                txTitle.textContent = "Edit Transaksi";
                txForm.action = `/investor-transaction/${d.id}`;
                txMethod.innerHTML = '<input type="hidden" name="_method" value="PUT">';
                txSubmit.textContent = "Simpan Perubahan";
                txCancel.style.display = "block";

                document.getElementById("tx_investor").value = d.investor;
                document.getElementById("tx_date").value = d.date;
                document.getElementById("tx_type").value = d.type;
                txAmount.value = d.amount;
                document.getElementById("tx_notes").value = d.notes || "";
                updatePreview();

                txForm.scrollIntoView({ behavior: "smooth", block: "center" });
            });
        });

        txCancel.addEventListener("click", () => {
            txTitle.textContent = "Catat Setoran / Penarikan";
            txForm.action = "{{ route('investor.transaction.store') }}";
            txMethod.innerHTML = "";
            txSubmit.textContent = "Simpan Transaksi";
            txCancel.style.display = "none";
            txForm.reset();
            document.getElementById("tx_date").value = "{{ now()->format('Y-m-d') }}";
            updatePreview();
        });

        // ---- Form investor: tambah / edit ----
        const invForm = document.getElementById("invForm");
        const invTitle = document.getElementById("invFormTitle");
        const invMethod = document.getElementById("invMethod");
        const invSubmit = document.getElementById("invSubmit");
        const invCancel = document.getElementById("invCancel");
        const invCode = document.getElementById("inv_code");

        document.querySelectorAll(".btn-edit-investor").forEach(btn => {
            btn.addEventListener("click", () => {
                const d = btn.dataset;
                invTitle.textContent = `Edit Investor: ${d.name}`;
                invForm.action = `/investor/${d.id}`;
                invMethod.innerHTML = '<input type="hidden" name="_method" value="PUT">';
                invSubmit.textContent = "Simpan Perubahan";
                invCancel.style.display = "block";

                invCode.value = d.code;
                document.getElementById("inv_name").value = d.name;
                document.getElementById("inv_join").value = d.join;
                document.getElementById("inv_status").value = d.status;
                document.getElementById("inv_notes").value = d.notes || "";

                invForm.scrollIntoView({ behavior: "smooth", block: "center" });
            });
        });

        invCancel.addEventListener("click", () => {
            invTitle.textContent = "Tambah Investor Baru";
            invForm.action = "{{ route('investor.store') }}";
            invMethod.innerHTML = "";
            invSubmit.textContent = "Tambah Investor";
            invCancel.style.display = "none";
            invForm.reset();
            invCode.value = "{{ $nextCode }}";
            document.getElementById("inv_join").value = "{{ now()->format('Y-m-d') }}";
        });

        document.getElementById("btnTambahInvestor").addEventListener("click", () => {
            invCancel.click();
            document.getElementById("investorFormCard").scrollIntoView({ behavior: "smooth", block: "center" });
            document.getElementById("inv_name").focus({ preventScroll: true });
        });
    });
</script>
@endsection
