@extends('layouts.app')

@section('title', $report->title())

@section('content')
<div class="page-header">
    <div class="page-title">
        <h1>{{ $report->title() }}</h1>
        <p><a href="{{ route('investor-report.index') }}" style="color: var(--text-muted);">&larr; Kembali ke daftar report</a></p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        @if(Auth::user()->isSuperAdmin())
            <a href="{{ route('investor-report.edit', $report->id) }}" class="btn btn-secondary">Edit</a>
        @endif
        <a href="{{ route('investor-report.pdf', $report->id) }}" target="_blank" class="btn btn-secondary">Lihat PDF</a>
        <a href="{{ route('investor-report.pdf', ['id' => $report->id, 'download' => 1]) }}" class="btn btn-primary">Download PDF</a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success mb-4">{{ session('success') }}</div>
@endif

<div style="background: #fff; border-radius: 12px; padding: clamp(1.25rem, 5vw, 3.5rem); max-width: 820px; margin: 0 auto; overflow-x: auto;">
    @include('investor_report._document')
</div>
@endsection
