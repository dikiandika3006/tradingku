@extends('layouts.app')

@section('title', 'Laporan Portofolio Akumulatif Bulanan')

@section('content')
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
    <div class="page-title">
        <h1 style="margin: 0; font-size: 1.8rem; font-weight: 700;">Laporan Bulanan Portofolio (Month-to-Month)</h1>
        <p style="margin-top: 0.25rem; color: var(--text-muted);">Monitoring pergerakan performa trading, PNL terealisasi, dan pertumbuhan portofolio secara akumulatif per bulan kalender.</p>
    </div>

    <!-- Year Filter Form -->
    <form method="GET" action="{{ route('portfolio.monthly-report') }}" class="year-filter-form" style="display: flex; align-items: center; gap: 0.5rem;">
        <label for="year" style="color: var(--text-muted); font-size: 0.9rem; font-weight: 500;">Filter Tahun:</label>
        <select name="year" id="year" onchange="this.form.submit()" style="background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.15); color: #fff; padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.9rem; outline: none; cursor: pointer;">
            <option value="all" {{ $selected_year === 'all' ? 'selected' : '' }} style="background: #1e1e2d;">Semua Tahun (All-Time)</option>
            @foreach($available_years as $yr)
                <option value="{{ $yr }}" {{ $selected_year === $yr ? 'selected' : '' }} style="background: #1e1e2d;">Tahun {{ $yr }}</option>
            @endforeach
        </select>
    </form>
</div>

<!-- Executive Summary Stats Grid -->
<div class="stats-grid mb-6" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="glass-card" style="padding: 1.25rem;">
        <div class="card-title" style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">Total PNL Akumulatif</div>
        <div class="stat-value {{ $all_time_net_pnl_usdt >= 0 ? 'text-success' : 'text-danger' }}" style="margin-top: 0.5rem; font-size: 1.8rem; font-weight: 700;">
            {{ $all_time_net_pnl_usdt >= 0 ? '+' : '' }}${{ number_format($all_time_net_pnl_usdt, 2) }}
        </div>
        <div class="stat-desc {{ $all_time_net_pnl_usdt >= 0 ? 'text-success' : 'text-danger' }}" style="font-size: 0.85rem; margin-top: 0.25rem;">
            ≈ {{ $all_time_net_pnl_usdt >= 0 ? '+' : '' }}Rp {{ number_format($all_time_net_pnl_idr, 0, ',', '.') }}
        </div>
    </div>

    <div class="glass-card" style="padding: 1.25rem;">
        <div class="card-title" style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">Bulan Terbaik (Best Month)</div>
        <div class="stat-value text-success" style="margin-top: 0.5rem; font-size: 1.8rem; font-weight: 700;">
            @if($best_month)
                +${{ number_format($best_month['net_pnl_usdt'], 2) }}
            @else
                $0.00
            @endif
        </div>
        <div class="stat-desc text-muted" style="font-size: 0.85rem; margin-top: 0.25rem;">
            {{ $best_month ? $best_month['month_name'] : '-' }}
        </div>
    </div>

    <div class="glass-card" style="padding: 1.25rem;">
        <div class="card-title" style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">Bulan Terburuk (Worst Month)</div>
        <div class="stat-value text-danger" style="margin-top: 0.5rem; font-size: 1.8rem; font-weight: 700;">
            @if($worst_month)
                {{ $worst_month['net_pnl_usdt'] >= 0 ? '+' : '' }}${{ number_format($worst_month['net_pnl_usdt'], 2) }}
            @else
                $0.00
            @endif
        </div>
        <div class="stat-desc text-muted" style="font-size: 0.85rem; margin-top: 0.25rem;">
            {{ $worst_month ? $worst_month['month_name'] : '-' }}
        </div>
    </div>

    <div class="glass-card" style="padding: 1.25rem;">
        <div class="card-title" style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">Win Rate Rata-rata Bulanan</div>
        <div class="stat-value text-info" style="margin-top: 0.5rem; font-size: 1.8rem; font-weight: 700;">
            {{ number_format($avg_monthly_win_rate, 1) }}%
        </div>
        <div class="stat-desc text-muted" style="font-size: 0.85rem; margin-top: 0.25rem;">
            Rasio kemenangan konsisten
        </div>
    </div>
</div>

<!-- Interactive Charts Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <!-- Bar Chart: Monthly Realized PNL -->
    <div class="glass-card" style="padding: 1.5rem;">
        <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; color: #fff;">Realized PNL Bulanan (USDT)</h3>
        <div style="position: relative; height: 260px; width: 100%;">
            <canvas id="monthlyPnlChart"></canvas>
        </div>
    </div>

    <!-- Line Chart: Cumulative Growth Curve -->
    <div class="glass-card" style="padding: 1.5rem;">
        <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; color: #fff;">Kurva Akumulasi Pertumbuhan Portofolio (MoM)</h3>
        <div style="position: relative; height: 260px; width: 100%;">
            <canvas id="cumulativePnlChart"></canvas>
        </div>
    </div>
</div>

<!-- Detailed Monthly Breakdown Table -->
<div class="glass-card" style="padding: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
        <h3 style="font-size: 1.15rem; font-weight: 600; color: #fff; margin: 0;">Rincian Pergerakan Bulanan (Month-to-Month)</h3>
        <span style="font-size: 0.85rem; color: var(--text-muted);">Urutan: Bulan Terbaru Pertama</span>
    </div>

    <div class="table-responsive" style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.1); color: var(--text-muted); font-size: 0.85rem;">
                    <th style="padding: 0.75rem 1rem;">Bulan & Tahun</th>
                    <th style="padding: 0.75rem 1rem; text-align: center;">Total Trade</th>
                    <th style="padding: 0.75rem 1rem; text-align: center;">Win Rate</th>
                    <th style="padding: 0.75rem 1rem; text-align: right;">Profit Terealisasi</th>
                    <th style="padding: 0.75rem 1rem; text-align: right;">Rugi Terealisasi</th>
                    <th style="padding: 0.75rem 1rem; text-align: right;">PNL Bersih Bulan Ini</th>
                    <th style="padding: 0.75rem 1rem; text-align: right;">Akumulasi PNL (MoM)</th>
                    <th style="padding: 0.75rem 1rem; text-align: center;">Pertumbuhan MoM</th>
                    <th style="padding: 0.75rem 1rem; text-align: center;">Aset Utama</th>
                </tr>
            </thead>
            <tbody>
                @forelse($monthly_reports as $report)
                <tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05); font-size: 0.9rem;">
                    <td style="padding: 1rem; font-weight: 600; color: #fff;">
                        {{ $report['month_name'] }}
                    </td>
                    <td style="padding: 1rem; text-align: center;">
                        <span class="badge" style="background: rgba(255,255,255,0.08); padding: 0.25rem 0.6rem; border-radius: 6px; font-weight: 600;">
                            {{ $report['trades_count'] }} trade
                        </span>
                    </td>
                    <td style="padding: 1rem; text-align: center;">
                        <span class="badge {{ $report['win_rate'] >= 50 ? 'text-success' : 'text-warning' }}" style="font-weight: 600;">
                            {{ number_format($report['win_rate'], 1) }}%
                        </span>
                    </td>
                    <td style="padding: 1rem; text-align: right;" class="text-success font-mono">
                        +${{ number_format($report['profit_usdt'], 2) }}
                    </td>
                    <td style="padding: 1rem; text-align: right;" class="text-danger font-mono">
                        -${{ number_format($report['loss_usdt'], 2) }}
                    </td>
                    <td style="padding: 1rem; text-align: right; font-weight: 700;" class="{{ $report['net_pnl_usdt'] >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ $report['net_pnl_usdt'] >= 0 ? '+' : '' }}${{ number_format($report['net_pnl_usdt'], 2) }}
                        <div style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">
                            ≈ {{ $report['net_pnl_usdt'] >= 0 ? '+' : '' }}Rp {{ number_format($report['net_pnl_idr'], 0, ',', '.') }}
                        </div>
                    </td>
                    <td style="padding: 1rem; text-align: right; font-weight: 700;" class="{{ $report['cumulative_pnl_usdt'] >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ $report['cumulative_pnl_usdt'] >= 0 ? '+' : '' }}${{ number_format($report['cumulative_pnl_usdt'], 2) }}
                        <div style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">
                            ≈ {{ $report['cumulative_pnl_usdt'] >= 0 ? '+' : '' }}Rp {{ number_format($report['cumulative_pnl_idr'], 0, ',', '.') }}
                        </div>
                    </td>
                    <td style="padding: 1rem; text-align: center;">
                        @if($report['mom_growth_percent'] > 0)
                            <span style="color: #22c55e; font-weight: 600; font-size: 0.85rem;">▲ +{{ number_format($report['mom_growth_percent'], 1) }}%</span>
                        @elseif($report['mom_growth_percent'] < 0)
                            <span style="color: #ef4444; font-weight: 600; font-size: 0.85rem;">▼ {{ number_format($report['mom_growth_percent'], 1) }}%</span>
                        @else
                            <span style="color: var(--text-muted); font-size: 0.85rem;">0.0%</span>
                        @endif
                    </td>
                    <td style="padding: 1rem; text-align: center;">
                        <span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); padding: 0.25rem 0.6rem; border-radius: 6px; font-weight: 600;">
                            {{ $report['top_asset'] }}
                        </span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" style="text-align: center; padding: 2rem; color: var(--text-muted);">
                        Belum ada riwayat transaksi terealisasi (Closed Position) yang dapat ditampilkan.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Chart.js Integration -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const chartReports = @json($chart_reports);

        const labels = chartReports.map(r => r.month_name);
        const netPnls = chartReports.map(r => r.net_pnl_usdt);
        const cumulativePnls = chartReports.map(r => r.cumulative_pnl_usdt);

        // Dynamic colors for Bar Chart based on Profit (+) / Loss (-)
        const barColors = netPnls.map(val => val >= 0 ? 'rgba(34, 197, 94, 0.85)' : 'rgba(239, 68, 68, 0.85)');
        const borderColors = netPnls.map(val => val >= 0 ? '#22c55e' : '#ef4444');

        // 1. Monthly Realized PNL Bar Chart
        const ctxPnl = document.getElementById('monthlyPnlChart').getContext('2d');
        new Chart(ctxPnl, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'PNL Bersih (USDT)',
                    data: netPnls,
                    backgroundColor: barColors,
                    borderColor: borderColors,
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let val = context.raw;
                                return 'PNL: ' + (val >= 0 ? '+' : '') + '$' + val.toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: 'rgba(255, 255, 255, 0.7)', font: { size: 11 } },
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }
                    },
                    y: {
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)',
                            font: { size: 11 },
                            callback: function(value) {
                                return (value >= 0 ? '+' : '') + '$' + value;
                            }
                        },
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }
                    }
                }
            }
        });

        // 2. Cumulative Growth Line Chart
        const ctxCumulative = document.getElementById('cumulativePnlChart').getContext('2d');
        new Chart(ctxCumulative, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Akumulasi PNL (USDT)',
                    data: cumulativePnls,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.15)',
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#60a5fa',
                    pointBorderColor: '#fff',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let val = context.raw;
                                return 'Akumulasi PNL: ' + (val >= 0 ? '+' : '') + '$' + val.toFixed(2);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: 'rgba(255, 255, 255, 0.7)', font: { size: 11 } },
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }
                    },
                    y: {
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)',
                            font: { size: 11 },
                            callback: function(value) {
                                return (value >= 0 ? '+' : '') + '$' + value;
                            }
                        },
                        grid: { color: 'rgba(255, 255, 255, 0.05)' }
                    }
                }
            }
        });
    });
</script>
@endsection
