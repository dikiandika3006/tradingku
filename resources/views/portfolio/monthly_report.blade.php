@extends('layouts.app')

@section('title', 'Laporan Portofolio Akumulatif Bulanan Transparan')

@section('content')
<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
    <div class="page-title">
        <h1 style="margin: 0; font-size: 1.8rem; font-weight: 700;">Laporan Bulanan Portofolio (Month-to-Month)</h1>
        <p style="margin-top: 0.25rem; color: var(--text-muted);">Monitoring transparan kinerja trading, PNL terealisasi, Floating Loss/Profit (posisi terbuka), serta True Net Portfolio Equity dari bulan ke bulan.</p>
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
<div class="stats-grid mb-6" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="glass-card" style="padding: 1.25rem;">
        <div class="card-title" style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">Realized PNL (Terealisasi)</div>
        <div class="stat-value {{ $all_time_net_pnl_usdt >= 0 ? 'text-success' : 'text-danger' }}" style="margin-top: 0.5rem; font-size: 1.7rem; font-weight: 700;">
            {{ $all_time_net_pnl_usdt >= 0 ? '+' : '' }}${{ number_format($all_time_net_pnl_usdt, 2) }}
        </div>
        <div class="stat-desc {{ $all_time_net_pnl_usdt >= 0 ? 'text-success' : 'text-danger' }}" style="font-size: 0.8rem; margin-top: 0.25rem;">
            ≈ {{ $all_time_net_pnl_usdt >= 0 ? '+' : '' }}Rp {{ number_format($all_time_net_pnl_idr, 0, ',', '.') }}
        </div>
    </div>

    <div class="glass-card" style="padding: 1.25rem;">
        <div class="card-title" style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">Floating PNL (Posisi Terbuka)</div>
        <div class="stat-value {{ $total_floating_pnl_usdt >= 0 ? 'text-success' : 'text-danger' }}" style="margin-top: 0.5rem; font-size: 1.7rem; font-weight: 700;">
            {{ $total_floating_pnl_usdt >= 0 ? '+' : '' }}${{ number_format($total_floating_pnl_usdt, 2) }}
        </div>
        <div class="stat-desc {{ $total_floating_pnl_usdt >= 0 ? 'text-success' : 'text-danger' }}" style="font-size: 0.8rem; margin-top: 0.25rem;">
            ≈ {{ $total_floating_pnl_usdt >= 0 ? '+' : '' }}Rp {{ number_format($total_floating_pnl_idr, 0, ',', '.') }}
        </div>
    </div>

    <div class="glass-card" style="padding: 1.25rem; border: 1px solid rgba(59, 130, 246, 0.4); background: rgba(59, 130, 246, 0.05);">
        <div class="card-title" style="color: #60a5fa; font-size: 0.85rem; font-weight: 600;">True Net Portfolio Equity</div>
        <div class="stat-value {{ $true_net_portfolio_pnl_usdt >= 0 ? 'text-success' : 'text-danger' }}" style="margin-top: 0.5rem; font-size: 1.7rem; font-weight: 700;">
            {{ $true_net_portfolio_pnl_usdt >= 0 ? '+' : '' }}${{ number_format($true_net_portfolio_pnl_usdt, 2) }}
        </div>
        <div class="stat-desc {{ $true_net_portfolio_pnl_usdt >= 0 ? 'text-success' : 'text-danger' }}" style="font-size: 0.8rem; margin-top: 0.25rem;">
            ≈ {{ $true_net_portfolio_pnl_usdt >= 0 ? '+' : '' }}Rp {{ number_format($true_net_portfolio_pnl_idr, 0, ',', '.') }}
        </div>
    </div>

    <div class="glass-card" style="padding: 1.25rem;">
        <div class="card-title" style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">Bulan Terbaik (Best Month)</div>
        <div class="stat-value text-success" style="margin-top: 0.5rem; font-size: 1.7rem; font-weight: 700;">
            @if($best_month)
                +${{ number_format($best_month['true_net_pnl_usdt'], 2) }}
            @else
                $0.00
            @endif
        </div>
        <div class="stat-desc text-muted" style="font-size: 0.8rem; margin-top: 0.25rem;">
            {{ $best_month ? $best_month['month_name'] : '-' }}
        </div>
    </div>
</div>

<!-- Interactive Charts Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <!-- Bar Chart: Monthly Realized vs Floating PNL -->
    <div class="glass-card" style="padding: 1.5rem;">
        <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; color: #fff;">Realized PNL vs True Net PNL Bulanan (USDT)</h3>
        <div style="position: relative; height: 260px; width: 100%;">
            <canvas id="monthlyPnlChart"></canvas>
        </div>
    </div>

    <!-- Line Chart: True Cumulative Portfolio Equity Curve -->
    <div class="glass-card" style="padding: 1.5rem;">
        <h3 style="font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; color: #fff;">Kurva Ekuitas Bersih Portofolio (True Portfolio Equity Curve)</h3>
        <div style="position: relative; height: 260px; width: 100%;">
            <canvas id="cumulativePnlChart"></canvas>
        </div>
    </div>
</div>

<!-- Detailed Monthly Breakdown Table -->
<div class="glass-card" style="padding: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;">
        <h3 style="font-size: 1.15rem; font-weight: 600; color: #fff; margin: 0;">Rincian Ekuitas Bulanan (Transparan: Realized + Floating)</h3>
        <span style="font-size: 0.85rem; color: var(--text-muted);">Urutan: Bulan Terbaru Pertama</span>
    </div>

    <div class="table-responsive" style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.1); color: var(--text-muted); font-size: 0.85rem;">
                    <th style="padding: 0.75rem 1rem;">Bulan & Tahun</th>
                    <th style="padding: 0.75rem 1rem; text-align: center;">Total Trade</th>
                    <th style="padding: 0.75rem 1rem; text-align: right;">Realized PNL</th>
                    <th style="padding: 0.75rem 1rem; text-align: right;">Floating PNL (Holding)</th>
                    <th style="padding: 0.75rem 1rem; text-align: right;">True Net PNL Bulan Ini</th>
                    <th style="padding: 0.75rem 1rem; text-align: right;">Valuasi Saldo Portofolio</th>
                    <th style="padding: 0.75rem 1rem; text-align: right;">Pertumbuhan Saldo (MoM)</th>
                    <th style="padding: 0.75rem 1rem; text-align: center;">Pertumbuhan %</th>
                    <th style="padding: 0.75rem 1rem; text-align: center;">Status Kesehatan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($monthly_reports as $report)
                <tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.05); font-size: 0.9rem;">
                    <td style="padding: 1rem; font-weight: 600; color: #fff;">
                        {{ $report['month_name'] }}
                        @if(!empty($report['is_finalized']))
                            <span title="Dikunci pada {{ $report['cut_off_date'] }} — nilai tidak akan berubah lagi"
                                  style="display: inline-block; margin-left: 0.4rem; background: rgba(148, 163, 184, 0.15); color: #94a3b8; padding: 0.1rem 0.45rem; border-radius: 5px; font-size: 0.7rem; font-weight: 600; vertical-align: middle;">
                                🔒 FINAL
                            </span>
                        @else
                            <span title="Bulan berjalan — nilai masih bergerak mengikuti harga pasar"
                                  style="display: inline-block; margin-left: 0.4rem; background: rgba(34, 197, 94, 0.15); color: #22c55e; padding: 0.1rem 0.45rem; border-radius: 5px; font-size: 0.7rem; font-weight: 600; vertical-align: middle;">
                                ● LIVE
                            </span>
                        @endif
                    </td>
                    <td style="padding: 1rem; text-align: center;">
                        <span class="badge" style="background: rgba(255,255,255,0.08); padding: 0.25rem 0.6rem; border-radius: 6px; font-weight: 600;">
                            {{ $report['trades_count'] }} trade
                        </span>
                    </td>
                    @php
                        $realizedPnlVal = $report['realized_pnl_usdt'] ?? $report['net_pnl_usdt'] ?? 0;
                        $floatingPnlVal = $report['floating_pnl_usdt'] ?? 0;
                        $trueNetPnlVal = $report['true_net_pnl_usdt'] ?? $realizedPnlVal;
                        $trueNetPnlIdrVal = $report['true_net_pnl_idr'] ?? 0;
                        $portValUsdt = $report['portfolio_valuation_usdt'] ?? 0;
                        $portValIdr = $report['portfolio_valuation_idr'] ?? 0;
                        $nomGrowthUsdt = $report['nominal_growth_usdt'] ?? 0;
                        $nomGrowthIdr = $report['nominal_growth_idr'] ?? 0;
                    @endphp
                    <td style="padding: 1rem; text-align: right; font-weight: 600;" class="{{ $realizedPnlVal >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ $realizedPnlVal >= 0 ? '+' : '' }}${{ number_format($realizedPnlVal, 2) }}
                    </td>
                    <td style="padding: 1rem; text-align: right; font-weight: 600;" class="{{ $floatingPnlVal >= 0 ? 'text-success' : 'text-danger' }}">
                        @if($floatingPnlVal != 0)
                            {{ $floatingPnlVal >= 0 ? '+' : '' }}${{ number_format($floatingPnlVal, 2) }}
                        @else
                            <span style="color: var(--text-muted); font-weight: 400;">$0.00</span>
                        @endif
                    </td>
                    <td style="padding: 1rem; text-align: right; font-weight: 700;" class="{{ $trueNetPnlVal >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ $trueNetPnlVal >= 0 ? '+' : '' }}${{ number_format($trueNetPnlVal, 2) }}
                        <div style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">
                            ≈ {{ $trueNetPnlVal >= 0 ? '+' : '' }}Rp {{ number_format($trueNetPnlIdrVal, 0, ',', '.') }}
                        </div>
                    </td>
                    <td style="padding: 1rem; text-align: right; font-weight: 700; color: #fff;">
                        ${{ number_format($portValUsdt, 2) }}
                        <div style="font-size: 0.75rem; font-weight: 400; opacity: 0.8; color: var(--text-muted);">
                            ≈ Rp {{ number_format($portValIdr, 0, ',', '.') }}
                        </div>
                    </td>
                    <td style="padding: 1rem; text-align: right; font-weight: 700;" class="{{ $nomGrowthUsdt >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ $nomGrowthUsdt >= 0 ? '+' : '' }}${{ number_format($nomGrowthUsdt, 2) }}
                        <div style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">
                            ≈ {{ $nomGrowthUsdt >= 0 ? '+' : '' }}Rp {{ number_format($nomGrowthIdr, 0, ',', '.') }}
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
                        <span class="badge" style="background: rgba(255, 255, 255, 0.08); padding: 0.3rem 0.7rem; border-radius: 6px; font-weight: 600; font-size: 0.8rem;">
                            {{ $report['health_badge'] }}
                        </span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-muted);">
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
        const realizedPnls = chartReports.map(r => r.realized_pnl_usdt);
        const trueNetPnls = chartReports.map(r => r.true_net_pnl_usdt);
        const trueCumulativePnls = chartReports.map(r => r.true_cumulative_pnl_usdt);

        // 1. Bar Chart: Realized PNL vs True Net PNL
        const ctxPnl = document.getElementById('monthlyPnlChart').getContext('2d');
        new Chart(ctxPnl, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Realized PNL',
                        data: realizedPnls,
                        backgroundColor: 'rgba(59, 130, 246, 0.75)',
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                        borderRadius: 4
                    },
                    {
                        label: 'True Net PNL (inc. Floating)',
                        data: trueNetPnls,
                        backgroundColor: trueNetPnls.map(val => val >= 0 ? 'rgba(34, 197, 94, 0.75)' : 'rgba(239, 68, 68, 0.75)'),
                        borderColor: trueNetPnls.map(val => val >= 0 ? '#22c55e' : '#ef4444'),
                        borderWidth: 1,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: 'rgba(255, 255, 255, 0.8)', font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let val = context.raw;
                                return context.dataset.label + ': ' + (val >= 0 ? '+' : '') + '$' + val.toFixed(2);
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

        // 2. Line Chart: True Cumulative Portfolio Equity Curve
        const ctxCumulative = document.getElementById('cumulativePnlChart').getContext('2d');
        new Chart(ctxCumulative, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'True Cumulative Portfolio Equity (USDT)',
                    data: trueCumulativePnls,
                    borderColor: '#22c55e',
                    backgroundColor: 'rgba(34, 197, 94, 0.12)',
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#4ade80',
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
                                return 'Akumulasi Ekuitas Bersih: ' + (val >= 0 ? '+' : '') + '$' + val.toFixed(2);
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
