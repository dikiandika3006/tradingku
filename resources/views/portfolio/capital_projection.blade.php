@extends('layouts.app')

@section('title', 'Proyeksi Modal')

@section('content')
@php
    $rp = fn($v) => 'Rp ' . number_format((float)$v, 0, ',', '.');
    $s  = $settings;
@endphp

<div class="page-header" style="margin-bottom: 1.5rem;">
    <h1 style="margin: 0; font-size: 1.8rem; font-weight: 700;">Proyeksi Modal</h1>
    <p style="margin-top: 0.25rem; color: var(--text-muted);">
        Perkiraan pertumbuhan modal bulanan beserta rencana alokasi BTC dan altcoin,
        dibandingkan dengan saldo portofolio yang sebenarnya.
    </p>
</div>

@if(session('success'))
    <div class="glass-card" style="padding: 1rem 1.25rem; margin-bottom: 1.5rem; border: 1px solid rgba(34,197,94,0.35); background: rgba(34,197,94,0.06); color: #22c55e;">
        {{ session('success') }}
    </div>
@endif

@if($errors->any())
    <div class="glass-card" style="padding: 1rem 1.25rem; margin-bottom: 1.5rem; border: 1px solid rgba(239,68,68,0.35); background: rgba(239,68,68,0.06);">
        <strong style="color: #ef4444;">Ada isian yang belum benar:</strong>
        <ul style="margin: 0.5rem 0 0 1.2rem; color: var(--text-muted); font-size: 0.9rem;">
            @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    </div>
@endif

<!-- Ringkasan -->
<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="glass-card" style="padding: 1.15rem;">
        <div style="color: var(--text-muted); font-size: 0.85rem;">Total Setoran</div>
        <div style="margin-top: 0.5rem; font-size: 1.3rem; font-weight: 700; color: #fff;">{{ $rp($summary['total_setoran']) }}</div>
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem;">
            modal awal + {{ number_format($summary['total_top_up'] > 0 ? $summary['total_top_up'] : 0, 0, ',', '.') }} top up
        </div>
    </div>
    <div class="glass-card" style="padding: 1.15rem;">
        <div style="color: var(--text-muted); font-size: 0.85rem;">Total Return</div>
        <div style="margin-top: 0.5rem; font-size: 1.3rem; font-weight: 700; color: #22c55e;">{{ $rp($summary['total_return']) }}</div>
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem;">hasil bunga berbunga</div>
    </div>
    <div class="glass-card" style="padding: 1.15rem; border: 1px solid rgba(59,130,246,0.4); background: rgba(59,130,246,0.05);">
        <div style="color: #60a5fa; font-size: 0.85rem; font-weight: 600;">Saldo Akhir Proyeksi</div>
        <div style="margin-top: 0.5rem; font-size: 1.3rem; font-weight: 700; color: #fff;">{{ $rp($summary['saldo_proyeksi']) }}</div>
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem;">sampai {{ $summary['bulan_terakhir'] }}</div>
    </div>
    <div class="glass-card" style="padding: 1.15rem;">
        <div style="color: var(--text-muted); font-size: 0.85rem;">Posisi Terkini</div>
        @php $pos = $summary['posisi_terkini']; @endphp
        @if($pos)
            <div style="margin-top: 0.5rem; font-size: 1.3rem; font-weight: 700; color: {{ $pos['selisih'] >= 0 ? '#22c55e' : '#ef4444' }};">
                {{ $pos['selisih'] >= 0 ? '+' : '' }}{{ $rp($pos['selisih']) }}
            </div>
            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem;">
                {{ $pos['selisih'] >= 0 ? 'di depan' : 'tertinggal dari' }} rencana &middot; {{ $pos['year_month'] }}
            </div>
        @else
            <div style="margin-top: 0.5rem; font-size: 1.3rem; font-weight: 700; color: var(--text-muted);">&mdash;</div>
            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem;">belum ada bulan yang terbanding</div>
        @endif
    </div>
</div>

<!-- Asumsi -->
<details class="glass-card" style="padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;" {{ $errors->any() ? 'open' : '' }}>
    <summary style="cursor: pointer; font-size: 1.05rem; font-weight: 600; color: #fff;">Ubah Asumsi Proyeksi</summary>

    <form method="POST" action="{{ route('portfolio.capital-projection.update') }}" style="margin-top: 1.25rem;">
        @csrf
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem;">
            <div class="form-group">
                <label class="form-label">Bulan Mulai</label>
                <input type="text" name="projection_start_month" class="form-control" placeholder="2026-09"
                       value="{{ old('projection_start_month', $s['projection_start_month']) }}">
            </div>
            <div class="form-group">
                <label class="form-label">Modal Awal (Rp)</label>
                <input type="number" step="1" min="0" name="projection_start_capital" class="form-control"
                       value="{{ old('projection_start_capital', $s['projection_start_capital']) }}">
            </div>
            <div class="form-group">
                <label class="form-label">Top Up per Bulan (Rp)</label>
                <input type="number" step="1" min="0" name="projection_monthly_topup" class="form-control"
                       value="{{ old('projection_monthly_topup', $s['projection_monthly_topup']) }}">
            </div>
            <div class="form-group">
                <label class="form-label">Return Default (%)</label>
                <input type="number" step="0.01" name="projection_default_return" class="form-control"
                       value="{{ old('projection_default_return', $s['projection_default_return']) }}">
            </div>
            <div class="form-group">
                <label class="form-label">Durasi (bulan)</label>
                <input type="number" step="1" min="1" max="240" name="projection_months" class="form-control"
                       value="{{ old('projection_months', $s['projection_months']) }}">
            </div>
            <div class="form-group" style="display: flex; align-items: flex-end;">
                <label style="display: flex; align-items: center; gap: 0.5rem; color: var(--text-muted); font-size: 0.9rem; cursor: pointer;">
                    <input type="checkbox" name="projection_topup_from_second" value="1"
                           {{ old('projection_topup_from_second', $s['projection_topup_from_second']) === '1' ? 'checked' : '' }}>
                    Top up mulai bulan kedua
                </label>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; margin-top: 0.5rem;">
            <div class="form-group">
                <label class="form-label">Return Khusus per Bulan</label>
                <textarea name="projection_return_overrides" class="form-control" rows="4"
                          style="font-family: monospace; font-size: 0.85rem;"
                          placeholder="2026-09: 9.80">{{ old('projection_return_overrides', $s['projection_return_overrides']) }}</textarea>
                <small style="color: var(--text-muted); font-size: 0.75rem;">
                    Satu baris per bulan, format <code>YYYY-MM: persen</code>. Bulan yang tidak disebut memakai return default.
                </small>
            </div>
            <div class="form-group">
                <label class="form-label">Fase Alokasi BTC (%)</label>
                <textarea name="projection_allocation_phases" class="form-control" rows="4"
                          style="font-family: monospace; font-size: 0.85rem;"
                          placeholder="2026-09: 20">{{ old('projection_allocation_phases', $s['projection_allocation_phases']) }}</textarea>
                <small style="color: var(--text-muted); font-size: 0.75rem;">
                    Berlaku sejak bulan tersebut sampai diganti baris berikutnya. Sisanya otomatis jadi porsi altcoin.
                </small>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top: 1rem; padding: 0.75rem 2rem; font-weight: 600;">
            Simpan Asumsi
        </button>
    </form>
</details>

<!-- Tabel proyeksi -->
<div class="glass-card" style="padding: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.75rem;">
        <div>
            <h3 style="font-size: 1.15rem; font-weight: 600; color: #fff; margin: 0;">Rincian Bulanan</h3>
            <span style="font-size: 0.8rem; color: var(--text-muted);">
                Kolom bertepi biru bisa diisi langsung. Kosongkan untuk kembali ke hitungan otomatis.
            </span>
        </div>
        <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
            <span style="font-size: 0.85rem; color: var(--text-muted);">
                {{ count($rows) }} bulan &middot; {{ $summary['bulan_dibanding'] }} terbanding
            </span>
            <button type="submit" form="formBaris" class="btn btn-primary" style="padding: 0.6rem 1.5rem; font-weight: 600;">
                Simpan Perubahan Baris
            </button>
        </div>
    </div>

    <form id="formBaris" method="POST" action="{{ route('portfolio.capital-projection.rows') }}">@csrf</form>

    <div class="table-responsive" style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); color: var(--text-muted); font-size: 0.82rem;">
                    <th style="padding: 0.7rem 0.9rem;">Bulan</th>
                    <th style="padding: 0.7rem 0.9rem; text-align: right;">Modal Awal</th>
                    <th style="padding: 0.7rem 0.9rem; text-align: right;">Top Up</th>
                    <th style="padding: 0.7rem 0.9rem; text-align: center;">Return</th>
                    <th style="padding: 0.7rem 0.9rem; text-align: right;">Return Rp</th>
                    <th style="padding: 0.7rem 0.9rem; text-align: right;">Saldo Akhir</th>
                    <th style="padding: 0.7rem 0.9rem; text-align: right;">Alokasi BTC</th>
                    <th style="padding: 0.7rem 0.9rem; text-align: right;">Alokasi Alt</th>
                    <th style="padding: 0.7rem 0.9rem; text-align: right;">Aktual</th>
                    <th style="padding: 0.7rem 0.9rem; text-align: right;">Selisih</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $r)
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.87rem;
                               {{ $r['alokasi_timpang'] ? 'background: rgba(234,179,8,0.07);' : ($r['sudah_lewat'] ? 'background: rgba(255,255,255,0.02);' : '') }}"
                        @if($r['alokasi_timpang']) title="Alokasi BTC + Alt tidak sama dengan saldo akhir" @endif>
                        <td style="padding: 0.75rem 0.9rem; font-weight: 600; color: #fff; white-space: nowrap;">
                            {{ $r['month_name'] }} {{ $r['year'] }}
                        </td>
                        <td style="padding: 0.75rem 0.9rem; text-align: right; font-family: monospace;">{{ $rp($r['modal_awal']) }}</td>
<td style="padding: 0.5rem 0.6rem; text-align: right;">
                            <input type="text" form="formBaris" name="rows[{{ $r['year_month'] }}][top_up]"
                                   value="{{ $r['ditimpa']['top_up'] ? number_format($r['top_up'], 0, ',', '.') : '' }}"
                                   placeholder="{{ number_format($r['top_up'], 0, ',', '.') }}"
                                   class="input-ribuan" inputmode="numeric" style="width: 100%; background: rgba(59,130,246,0.07); border: 1px solid rgba(59,130,246,0.3); border-radius: 5px; color: #fff; font-family: monospace; font-size: 0.83rem; padding: 0.35rem 0.5rem; text-align: right;">
                        </td>
                        <td style="padding: 0.75rem 0.9rem; text-align: center; font-family: monospace; color: #60a5fa;">
                            {{ number_format($r['return_persen'], 2) }}%
                        </td>
<td style="padding: 0.5rem 0.6rem; text-align: right;">
                            <input type="text" form="formBaris" name="rows[{{ $r['year_month'] }}][return_idr]"
                                   value="{{ $r['ditimpa']['return_idr'] ? number_format($r['return_idr'], 0, ',', '.') : '' }}"
                                   placeholder="{{ number_format($r['return_idr'], 0, ',', '.') }}"
                                   class="input-ribuan" inputmode="numeric" style="width: 100%; background: rgba(59,130,246,0.07); border: 1px solid rgba(59,130,246,0.3); border-radius: 5px; color: #fff; font-family: monospace; font-size: 0.83rem; padding: 0.35rem 0.5rem; text-align: right;">
                        </td>
                        <td style="padding: 0.75rem 0.9rem; text-align: right; font-family: monospace; font-weight: 700; color: #fff;">{{ $rp($r['saldo_akhir']) }}</td>
<td style="padding: 0.5rem 0.6rem; text-align: right; background: rgba(247,147,26,0.05);">
                            <input type="text" form="formBaris" name="rows[{{ $r['year_month'] }}][alokasi_btc]"
                                   value="{{ $r['ditimpa']['alokasi_btc'] ? number_format($r['alokasi_btc'], 0, ',', '.') : '' }}"
                                   placeholder="{{ number_format($r['alokasi_btc'], 0, ',', '.') }}"
                                   class="input-ribuan" inputmode="numeric" style="width: 100%; background: rgba(59,130,246,0.07); border: 1px solid rgba(59,130,246,0.3); border-radius: 5px; color: #fff; font-family: monospace; font-size: 0.83rem; padding: 0.35rem 0.5rem; text-align: right;">
                            <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.2rem;">{{ number_format($r['btc_persen'], 1) }}%</div>
                        </td>
<td style="padding: 0.5rem 0.6rem; text-align: right; background: rgba(99,102,241,0.05);">
                            <input type="text" form="formBaris" name="rows[{{ $r['year_month'] }}][alokasi_alt]"
                                   value="{{ $r['ditimpa']['alokasi_alt'] ? number_format($r['alokasi_alt'], 0, ',', '.') : '' }}"
                                   placeholder="{{ number_format($r['alokasi_alt'], 0, ',', '.') }}"
                                   class="input-ribuan" inputmode="numeric" style="width: 100%; background: rgba(59,130,246,0.07); border: 1px solid rgba(59,130,246,0.3); border-radius: 5px; color: #fff; font-family: monospace; font-size: 0.83rem; padding: 0.35rem 0.5rem; text-align: right;">
                            <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.2rem;">{{ number_format($r['alt_persen'], 1) }}%</div>
                        </td>
                        <td style="padding: 0.75rem 0.9rem; text-align: right; font-family: monospace; font-weight: 600; color: #fff;">
                            {{ $r['aktual'] !== null ? $rp($r['aktual']) : '—' }}
                        </td>
                        <td style="padding: 0.75rem 0.9rem; text-align: right; font-family: monospace; font-weight: 700;
                                   color: {{ $r['selisih'] === null ? 'var(--text-muted)' : ($r['selisih'] >= 0 ? '#22c55e' : '#ef4444') }};">
                            @if($r['selisih'] === null)
                                —
                            @else
                                {{ $r['selisih'] >= 0 ? '+' : '' }}{{ $rp($r['selisih']) }}
                                <div style="font-size: 0.7rem; font-weight: 500;">
                                    {{ $r['selisih_persen'] >= 0 ? '+' : '' }}{{ number_format($r['selisih_persen'], 2) }}%
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div style="margin-top: 1rem; font-size: 0.78rem; color: var(--text-muted); line-height: 1.6;">
        Isian baris selalu menang atas hitungan otomatis, dan mengubah satu baris menggeser seluruh bulan sesudahnya.
        Mengisi Alokasi BTC saja sudah cukup &mdash; porsi altcoin otomatis mengikuti sisa saldo.
        Baris berlatar kuning berarti BTC + Alt tidak menjumlah ke saldo akhir.
        <form method="POST" action="{{ route('portfolio.capital-projection.reset') }}" style="display: inline;"
              onsubmit="return confirm('Hapus semua isian manual dan kembalikan ke hitungan otomatis?')">
            @csrf
            <button type="submit" style="background: none; border: none; color: #ef4444; text-decoration: underline; cursor: pointer; font-size: 0.78rem; padding: 0;">
                Kembalikan semua ke otomatis
            </button>
        </form>
        <br><br>
        Return dihitung dari modal awal bulan itu; top-up ditambahkan ke saldo tapi belum ikut dibungakan pada bulan yang sama.
        Kolom <strong>Aktual</strong> diambil dari snapshot portofolio bulanan, jadi hanya terisi untuk bulan yang sudah terjadi.
        Baris berlatar lebih terang berarti bulan tersebut sudah punya data nyata.
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Format ribuan sambil mengetik. Posisi kursor dijaga dari sisi KANAN,
    // karena penyisipan titik menggeser indeks dari kiri dan membuat kursor
    // melompat kalau dihitung dari depan.
    function formatRibuan(el) {
        const dariKanan = el.value.length - (el.selectionStart ?? el.value.length);
        const angka = el.value.replace(/\D/g, '');

        el.value = angka === '' ? '' : Number(angka).toLocaleString('id-ID');

        const posisi = Math.max(0, el.value.length - dariKanan);
        try { el.setSelectionRange(posisi, posisi); } catch (e) {}
    }

    document.querySelectorAll('.input-ribuan').forEach(function (el) {
        el.addEventListener('input', function () { formatRibuan(el); });
        el.addEventListener('blur', function () { formatRibuan(el); });
    });
});
</script>
@endsection
