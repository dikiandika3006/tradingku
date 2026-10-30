@extends('layouts.app')

@section('title', 'Investor Report')

@php
    $rp = fn ($v) => 'Rp ' . number_format($v, 0, ',', '.');
    $tgl = fn ($d) => $d->locale('id')->translatedFormat('d M Y');
@endphp

@section('content')
<div class="page-header">
    <div class="page-title">
        <h1>Investor Report</h1>
        <p>Laporan berkala untuk investor: perkembangan dana, posisi investor, dan kondisi portfolio</p>
    </div>
    @if(Auth::user()->isSuperAdmin())
        <a href="{{ route('investor-report.create') }}" class="btn btn-primary">+ Buat Report</a>
    @endif
</div>

@if(session('success'))
    <div class="alert alert-success mb-4">{{ session('success') }}</div>
@endif

<div class="glass-card">
    <div class="table-responsive">
        <table class="custom-table table-kartu">
            <thead>
                <tr>
                    <th>Periode</th>
                    <th style="text-align: right;">Total Modal</th>
                    <th style="text-align: right;">Nilai Portfolio</th>
                    <th style="text-align: right;">Profit</th>
                    <th style="text-align: right;">Return</th>
                    <th style="text-align: right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($reports as $r)
                    @php $report = $r['report']; $akhir = $r['akhir']; @endphp
                    <tr>
                        <td class="kartu-judul">
                            <a href="{{ route('investor-report.show', $report->id) }}" style="font-weight: 700; color: inherit;">{{ $tgl($report->period_start) }} – {{ $tgl($report->period_end) }}</a>
                        </td>
                        <td data-label="Total Modal" style="text-align: right; font-family: monospace;">{{ $rp($akhir['modal']) }}</td>
                        <td data-label="Nilai Portfolio" style="text-align: right; font-family: monospace;">{{ $rp($akhir['nilai']) }}</td>
                        <td data-label="Profit" style="text-align: right; font-family: monospace;" class="{{ $akhir['profit'] < 0 ? 'text-danger' : 'text-success' }}">{{ $rp($akhir['profit']) }}</td>
                        <td data-label="Return" style="text-align: right; font-family: monospace;" class="{{ $akhir['return'] < 0 ? 'text-danger' : 'text-success' }}">{{ number_format($akhir['return'], 2, ',', '.') }}%</td>
                        <td data-label="Aksi" style="text-align: right;">
                            <div style="display: inline-flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; justify-content: flex-end;">
                                <a href="{{ route('investor-report.show', $report->id) }}" class="btn btn-secondary btn-sm">Lihat</a>
                                <a href="{{ route('investor-report.pdf', ['id' => $report->id, 'download' => 1]) }}" class="btn btn-secondary btn-sm">PDF</a>
                                @if(Auth::user()->isSuperAdmin())
                                <a href="{{ route('investor-report.edit', $report->id) }}" class="btn btn-secondary btn-sm">Edit</a>
                                <form action="{{ route('investor-report.destroy', $report->id) }}" method="POST" onsubmit="return confirm('Hapus report periode ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Hapus report" style="background: none; border: none; color: #ef4444; cursor: pointer; display: flex;">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="kartu-kosong" style="text-align: center; color: var(--text-muted); padding: 3rem 0;">
                            Belum ada report.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
