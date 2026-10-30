@extends('layouts.app')

@section('title', $report ? 'Edit Investor Report' : 'Buat Investor Report')

@php
    $d = $defaults;
    $val = fn ($key) => old($key, isset($d[$key]) && $d[$key] !== null ? (is_float($d[$key]) ? round($d[$key], 2) : $d[$key]) : '');
@endphp

@section('content')
<div class="page-header">
    <div class="page-title">
        <h1>{{ $report ? 'Edit Investor Report' : 'Buat Investor Report' }}</h1>
        <p><a href="{{ route('investor-report.index') }}" style="color: var(--text-muted);">&larr; Kembali ke daftar report</a></p>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-4">{{ $errors->first() }}</div>
@endif

<form action="{{ $report ? route('investor-report.update', $report->id) : route('investor-report.store') }}" method="POST" id="reportForm">
    @csrf
    @if($report) @method('PUT') @endif

    <div class="glass-card mb-6">
        <div class="card-title"><span>Periode</span></div>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0;">
            Cukup pilih tanggal. Nilai portfolio, komposisi aset, kurs, dan harga BTC dihitung otomatis dari jurnal trade
            (mutasi deposit, beli, jual) dan harga penutupan harian Tokocrypto. Modal & posisi investor diambil dari Master Investor.
        </p>
        <div class="form-grid-2col">
            <div class="form-group">
                <label for="period_start" class="form-label">Tanggal Awal Periode</label>
                <input type="date" name="period_start" id="period_start" class="form-control" value="{{ $val('period_start') }}" required>
            </div>
            <div class="form-group">
                <label for="period_end" class="form-label">Tanggal Akhir Periode (tanggal report)</label>
                <input type="date" name="period_end" id="period_end" class="form-control" value="{{ $val('period_end') }}" required>
            </div>
        </div>
        <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
            <button type="button" class="btn btn-secondary btn-sm" id="btnSuggest">Hitung ulang otomatis</button>
            <span id="suggestStatus" style="font-size: 0.85rem; color: var(--text-muted);"></span>
        </div>
    </div>

    <div class="glass-card mb-6">
        <div class="card-title"><span>Nilai Portfolio</span></div>
        <div class="form-grid-2col">
            <div class="form-group">
                <label for="start_portfolio_value" class="form-label">Nilai Portfolio Awal Periode (Rp)</label>
                <input type="number" name="start_portfolio_value" id="start_portfolio_value" class="form-control" min="0" step="1" value="{{ $val('start_portfolio_value') }}" placeholder="otomatis">
                <small id="start_hint" style="color: var(--text-muted);"></small>
            </div>
            <div class="form-group">
                <label for="end_portfolio_value" class="form-label">Nilai Portfolio Akhir Periode (Rp)</label>
                <input type="number" name="end_portfolio_value" id="end_portfolio_value" class="form-control" min="0" step="1" value="{{ $val('end_portfolio_value') }}" placeholder="otomatis">
                <small id="end_hint" style="color: var(--text-muted);"></small>
            </div>
            <div class="form-group">
                <label for="btc_start_price" class="form-label">Harga BTC Awal Periode (USDT, benchmark)</label>
                <input type="number" name="btc_start_price" id="btc_start_price" class="form-control" min="0" step="0.01" value="{{ $val('btc_start_price') }}" placeholder="otomatis">
            </div>
            <div class="form-group">
                <label for="btc_end_price" class="form-label">Harga BTC Akhir Periode (USDT, benchmark)</label>
                <input type="number" name="btc_end_price" id="btc_end_price" class="form-control" min="0" step="0.01" value="{{ $val('btc_end_price') }}" placeholder="otomatis">
            </div>
        </div>
    </div>

    <div class="glass-card mb-6">
        <div class="card-title"><span>Kondisi Portfolio</span></div>
        <div class="form-group">
            <label for="allocation_text" class="form-label">Komposisi Aset di Akhir Periode (satu aset per baris, format: <code>ASET = persen</code>)</label>
            <textarea name="allocation_text" id="allocation_text" class="form-control" rows="6" style="font-family: monospace;" placeholder="otomatis">{{ $val('allocation_text') }}</textarea>
        </div>
        <div class="form-group">
            <label for="portfolio_note" class="form-label">Keterangan Kondisi Portfolio (opsional)</label>
            <textarea name="portfolio_note" id="portfolio_note" class="form-control" rows="3">{{ $val('portfolio_note') }}</textarea>
        </div>
    </div>

    <div class="glass-card mb-6">
        <div class="card-title"><span>Catatan Pengelola</span></div>
        <div class="form-group">
            <label for="manager_notes" class="form-label">Isi catatan — pisahkan paragraf dengan baris kosong, tulis <code>**teks**</code> untuk huruf tebal</label>
            <textarea name="manager_notes" id="manager_notes" class="form-control" rows="9">{{ $val('manager_notes') }}</textarea>
        </div>
    </div>

    <div style="display: flex; gap: 0.5rem;">
        <button type="submit" class="btn btn-primary">{{ $report ? 'Simpan Perubahan' : 'Buat Report' }}</button>
        <a href="{{ $report ? route('investor-report.show', $report->id) : route('investor-report.index') }}" class="btn btn-secondary">Batal</a>
    </div>
</form>

<script>
(function () {
    const $ = (id) => document.getElementById(id);
    const status = $('suggestStatus');
    const rupiah = (v) => 'Rp ' + Math.round(v).toLocaleString('id-ID');
    let seq = 0;

    async function suggest() {
        const start = $('period_start').value, end = $('period_end').value;
        if (!start || !end) return;

        const mine = ++seq;
        status.textContent = 'Menghitung dari jurnal trade & harga historis…';
        try {
            const params = new URLSearchParams({ period_start: start, period_end: end });
            @if($report) params.set('except', '{{ $report->id }}'); @endif
            const res = await fetch('{{ route('investor-report.suggest') }}?' + params, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error((await res.json()).message || res.statusText);
            const s = await res.json();
            if (mine !== seq) return; // sudah ada permintaan yang lebih baru

            const fill = (id, v) => { $(id).value = v ?? ''; };
            fill('start_portfolio_value', s.start_portfolio_value);
            fill('end_portfolio_value', s.end_portfolio_value);
            fill('btc_start_price', s.btc_start_price);
            fill('btc_end_price', s.btc_end_price);
            fill('allocation_text', s.allocation_text);
            if (!$('portfolio_note').value.trim()) fill('portfolio_note', s.portfolio_note);

            $('start_hint').textContent = s.start_message || (s.start_portfolio_value !== null ? s.start_source + ': ' + rupiah(s.start_portfolio_value) : '');
            $('end_hint').textContent = s.end_message || (s.end_portfolio_value !== null ? 'Dihitung dari jurnal trade: ' + rupiah(s.end_portfolio_value) : '');
            status.textContent = 'Terisi otomatis. Periksa dulu, lalu ubah bila perlu.';
        } catch (e) {
            if (mine === seq) status.textContent = 'Gagal menghitung otomatis: ' + e.message;
        }
    }

    $('btnSuggest').addEventListener('click', suggest);
    $('period_start').addEventListener('change', suggest);
    $('period_end').addEventListener('change', suggest);

    // Report baru: langsung hitung untuk tanggal default.
    @unless($report || old('period_start')) suggest(); @endunless
})();
</script>
@endsection
