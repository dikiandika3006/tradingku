@extends('layouts.app')

@section('title', 'Analisa Derivatives')

@section('content')
<div class="page-header">
    <div class="page-title">
        <h1>Analisa Derivatives</h1>
        <p>Input data dari Coinglass untuk analisa signal arah dan deteksi bottom</p>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success mb-4">
        {{ session('success') }}
    </div>
@endif

<div class="dashboard-row" style="grid-template-columns: 1fr 2.5fr; gap: 1.5rem;">
    <!-- Left Column: Input Form -->
    <div class="glass-card" style="height: fit-content;">
        <div class="card-title">Input Data Derivatives</div>

        <form action="{{ route('derivatives.store') }}" method="POST">
            @csrf

            <div class="form-group">
                <label for="deriv_symbol" class="form-label">Symbol (e.g. STRK, FET)</label>
                <div style="display: flex; gap: 0.5rem;">
                    <input type="text" name="symbol" id="deriv_symbol" class="form-control" placeholder="FET" style="text-transform: uppercase;" required>
                    <button type="button" id="btnAutoFill" class="btn btn-secondary" style="padding: 0.6rem; font-size: 0.75rem; white-space: nowrap;" title="Ambil data dari Binance Futures">
                        Auto-Fill
                    </button>
                </div>
                <div id="autoFillStatus" style="margin-top: 0.4rem; font-size: 0.7rem; display: none;"></div>
            </div>

            <div style="border-top: 1px solid var(--border-light); margin: 1rem 0; padding-top: 1rem;">
                <span class="form-label" style="font-weight: 700; color: var(--color-primary);">Data Long/Short</span>
            </div>

            <div class="form-group">
                <label for="ls_ratio_volume" class="form-label">L/S Ratio (24h) <span style="color: var(--color-danger);">*</span> <span style="font-size: 0.6rem; color: #f59e0b;">📋 COINGLASS</span></label>
                <input type="number" name="ls_ratio_volume" id="ls_ratio_volume" step="any" class="form-control" placeholder="0.0775" required>
                <small style="color: var(--text-muted); font-size: 0.7rem;">Angka dari Coinglass "Long/Short (24h)" — data multi-exchange</small>
            </div>

            <div class="form-grid-2col" style="margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="ls_ratio_accounts" class="form-label">L/S Akun Binance <span style="font-size: 0.55rem; color: var(--color-success);">🔄 AUTO</span></label>
                    <input type="number" name="ls_ratio_accounts" id="ls_ratio_accounts" step="any" class="form-control" placeholder="1.9568">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="ls_ratio_top_traders" class="form-label">L/S Top Traders <span style="font-size: 0.55rem; color: var(--color-success);">🔄 AUTO</span></label>
                    <input type="number" name="ls_ratio_top_traders" id="ls_ratio_top_traders" step="any" class="form-control" placeholder="2.5112">
                </div>
            </div>

            <div style="border-top: 1px solid var(--border-light); margin: 1rem 0; padding-top: 1rem;">
                <span class="form-label" style="font-weight: 700; color: var(--color-primary);">Market Data</span>
            </div>

            <div class="form-group">
                <label for="funding_rate" class="form-label">Funding Rate <span style="font-size: 0.55rem; color: var(--color-success);">🔄 AUTO</span></label>
                <input type="number" name="funding_rate" id="funding_rate" step="any" class="form-control" placeholder="0.0100">
            </div>

            <div class="form-grid-2col" style="margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="open_interest_change" class="form-label">OI Change (%) <span style="color: var(--color-danger);">*</span> <span style="font-size: 0.6rem; color: #f59e0b;">📋 CG</span></label>
                    <input type="number" name="open_interest_change" id="open_interest_change" step="any" class="form-control" placeholder="+17.08" required>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="volume_change" class="form-label">Volume Change (%) <span style="color: var(--color-danger);">*</span> <span style="font-size: 0.6rem; color: #f59e0b;">📋 CG</span></label>
                    <input type="number" name="volume_change" id="volume_change" step="any" class="form-control" placeholder="+770.89" required>
                </div>
            </div>

            <div class="form-group">
                <label for="price_change" class="form-label">Price Change (%) <span style="color: var(--color-danger);">*</span> <span style="font-size: 0.55rem; color: var(--color-success);">🔄 AUTO</span></label>
                <input type="number" name="price_change" id="price_change" step="any" class="form-control" placeholder="-8.14" required>
            </div>

            <div style="border-top: 1px solid var(--border-light); margin: 1rem 0; padding-top: 1rem;">
                <span class="form-label" style="font-weight: 700; color: var(--color-primary);">Liquidation 24h (USD)</span>
            </div>

            <div class="form-grid-2col" style="margin-bottom: 1rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="liq_long_24h" class="form-label">Liq Long ($)</label>
                    <input type="number" name="liq_long_24h" id="liq_long_24h" step="any" class="form-control" placeholder="90160" value="0">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="liq_short_24h" class="form-label">Liq Short ($)</label>
                    <input type="number" name="liq_short_24h" id="liq_short_24h" step="any" class="form-control" placeholder="8400" value="0">
                </div>
            </div>

            <div class="form-group">
                <label for="deriv_notes" class="form-label">Catatan (Opsional)</label>
                <textarea name="notes" id="deriv_notes" class="form-control" rows="2" placeholder="Whale dump, short squeeze, dsb..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                Analisa Sekarang
            </button>
        </form>
    </div>

    <!-- Right Column: Results List -->
    <div>
        @forelse($analyses as $a)
        <div class="glass-card" style="margin-bottom: 1.5rem;">
            <!-- Header -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span style="font-size: 1.4rem; font-weight: 800;">{{ $a->symbol }}</span>
                    <span style="
                        padding: 4px 12px;
                        border-radius: 20px;
                        font-size: 0.7rem;
                        font-weight: 700;
                        {{ in_array($a->signal, ['STRONG_BEARISH', 'BEARISH'])
                            ? 'background: var(--color-danger-bg); color: var(--color-danger);'
                            : (in_array($a->signal, ['BULLISH', 'STRONG_BULLISH'])
                                ? 'background: var(--color-success-bg); color: var(--color-success);'
                                : 'background: rgba(255,255,255,0.05); color: var(--text-muted);') }}
                    ">{{ $a->signal_label }}</span>
                </div>
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span style="color: var(--text-muted); font-size: 0.75rem;">{{ $a->created_at->format('d M Y H:i') }}</span>
                    <form action="{{ route('derivatives.destroy', $a->id) }}" method="POST" onsubmit="return confirm('Hapus analisa ini?')" style="margin: 0;">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-secondary" style="padding: 0.4rem 0.6rem; font-size: 0.7rem;">✕</button>
                    </form>
                </div>
            </div>

            <!-- KPI Grid -->
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">
                <div class="glass-card" style="padding: 0.75rem 1rem; text-align: center;">
                    <div class="stat-value" style="font-size: 1.2rem; {{ $a->price_change < 0 ? 'color: var(--color-danger);' : 'color: var(--color-success);' }}">
                        {{ $a->price_change > 0 ? '+' : '' }}{{ number_format($a->price_change, 2) }}%
                    </div>
                    <div class="stat-desc" style="font-size: 0.7rem;">Harga 24h</div>
                </div>
                <div class="glass-card" style="padding: 0.75rem 1rem; text-align: center;">
                    <div class="stat-value" style="font-size: 1.2rem; {{ $a->open_interest_change < 0 ? 'color: var(--color-danger);' : 'color: var(--color-success);' }}">
                        {{ $a->open_interest_change > 0 ? '+' : '' }}{{ number_format($a->open_interest_change, 2) }}%
                    </div>
                    <div class="stat-desc" style="font-size: 0.7rem;">Open Interest</div>
                </div>
                <div class="glass-card" style="padding: 0.75rem 1rem; text-align: center;">
                    <div class="stat-value" style="font-size: 1.2rem; {{ $a->volume_change > 100 ? 'color: var(--color-secondary);' : '' }}">
                        +{{ number_format($a->volume_change, 0) }}%
                    </div>
                    <div class="stat-desc" style="font-size: 0.7rem;">Volume 24h</div>
                </div>
                <div class="glass-card" style="padding: 0.75rem 1rem; text-align: center;">
                    <div class="stat-value" style="font-size: 1.2rem;">
                        {{ $a->bottom_score }}/5
                    </div>
                    <div class="stat-desc" style="font-size: 0.7rem;">Skor Bottom</div>
                </div>
            </div>

            <!-- Long vs Short Bar -->
            <div style="margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 0.4rem;">
                    <span style="color: var(--color-success); font-weight: 700;">Long {{ $a->long_percent_volume }}%</span>
                    <span style="font-size: 0.65rem; color: var(--text-muted);">L/S Ratio Volume: {{ number_format($a->ls_ratio_volume, 4) }}</span>
                    <span style="color: var(--color-danger); font-weight: 700;">Short {{ $a->short_percent_volume }}%</span>
                </div>
                <div style="display: flex; height: 10px; border-radius: 5px; overflow: hidden; background: rgba(255,255,255,0.05);">
                    <div style="width: {{ $a->long_percent_volume }}%; background: linear-gradient(90deg, #00e676, #00c853); transition: width 0.5s;"></div>
                    <div style="width: {{ $a->short_percent_volume }}%; background: linear-gradient(90deg, #ff5252, #ff1744); transition: width 0.5s;"></div>
                </div>

                @if($a->ls_ratio_accounts)
                <div style="display: flex; justify-content: space-between; font-size: 0.65rem; margin-top: 0.5rem; color: var(--text-muted);">
                    <span>Akun: Long {{ $a->long_percent_accounts }}% / Short {{ $a->short_percent_accounts }}%</span>
                    @if($a->ls_ratio_top_traders)
                    <span>Top Traders: Long {{ $a->long_percent_top_traders }}%</span>
                    @endif
                </div>
                @endif
            </div>

            <!-- Liquidation -->
            @if($a->total_liquidation > 0)
            <div style="margin-bottom: 1.25rem; padding: 0.75rem; background: rgba(255,255,255,0.02); border-radius: 10px; border: 1px solid var(--border-light);">
                <div style="font-size: 0.7rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.4rem;">Liquidation 24h</div>
                <div style="display: flex; justify-content: space-between; font-size: 0.8rem;">
                    <span style="color: var(--color-success);">Long: ${{ number_format($a->liq_long_24h, 0) }} <span style="font-size: 0.65rem;">({{ $a->liq_long_percent }}%)</span></span>
                    <span style="color: var(--color-danger);">Short: ${{ number_format($a->liq_short_24h, 0) }}</span>
                </div>
                <div style="display: flex; height: 6px; border-radius: 3px; overflow: hidden; background: rgba(255,255,255,0.05); margin-top: 0.4rem;">
                    <div style="width: {{ $a->liq_long_percent }}%; background: var(--color-success);"></div>
                    <div style="width: {{ 100 - $a->liq_long_percent }}%; background: var(--color-danger);"></div>
                </div>
            </div>
            @endif

            <!-- Bottom Checklist -->
            <div style="padding: 0.75rem; background: rgba(255,255,255,0.02); border-radius: 10px; border: 1px solid var(--border-light);">
                <div style="font-size: 0.7rem; font-weight: 600; color: var(--color-primary); margin-bottom: 0.6rem;">
                    ✅ CHECKLIST TANDA BOTTOM ({{ $a->bottom_score }}/5)
                </div>
                @foreach($a->bottom_checklist as $item)
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.35rem; font-size: 0.75rem;">
                    <span style="font-size: 0.85rem;">
                        @if($item['status'] === 'yes') ✅
                        @elseif($item['status'] === 'pending') ⏳
                        @else ❌
                        @endif
                    </span>
                    <span style="flex: 1; {{ $item['status'] === 'yes' ? 'color: var(--color-success);' : ($item['status'] === 'pending' ? 'color: #f59e0b;' : 'color: var(--text-muted);') }}">
                        {{ $item['label'] }}
                    </span>
                    <span style="font-size: 0.65rem; color: var(--text-muted);">{{ $item['value'] }}</span>
                </div>
                @endforeach
            </div>

            <!-- Recommendation -->
            <div style="margin-top: 1rem; padding: 0.75rem; border-radius: 10px;
                {{ $a->bottom_score >= 4
                    ? 'background: var(--color-success-bg); border: 1px solid rgba(0, 230, 118, 0.2);'
                    : ($a->bottom_score >= 2
                        ? 'background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.2);'
                        : 'background: var(--color-danger-bg); border: 1px solid rgba(255, 23, 68, 0.2);') }}
            ">
                <div style="font-size: 0.75rem; font-weight: 700;">
                    @if($a->bottom_score >= 4)
                        🟢 REKOMENDASI: Mulai cicil entry long. Tanda-tanda bottom sudah kuat.
                    @elseif($a->bottom_score >= 3)
                        🟡 REKOMENDASI: Mendekati bottom. Monitor ketat, siapkan order limit.
                    @elseif($a->bottom_score >= 2)
                        🟡 REKOMENDASI: Belum bottom. Tunggu lebih banyak konfirmasi.
                    @else
                        🔴 REKOMENDASI: Jangan entry long. Tekanan jual masih sangat kuat.
                    @endif
                </div>
            </div>

            @if($a->notes)
            <div style="margin-top: 0.75rem; font-size: 0.75rem; color: var(--text-muted); font-style: italic;">
                📝 {{ $a->notes }}
            </div>
            @endif
        </div>
        @empty
        <div class="glass-card" style="text-align: center; padding: 3rem;">
            <div style="font-size: 2.5rem; margin-bottom: 1rem; opacity: 0.3;">📊</div>
            <p style="color: var(--text-muted);">Belum ada data analisa derivatives.</p>
            <p style="color: var(--text-muted); font-size: 0.8rem;">Masukkan data dari Coinglass di form sebelah kiri untuk memulai analisa.</p>
        </div>
        @endforelse

        {{ $analyses->links() }}
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnAutoFill = document.getElementById('btnAutoFill');
    const symbolInput = document.getElementById('deriv_symbol');
    const statusEl = document.getElementById('autoFillStatus');

    function showStatus(message, type) {
        statusEl.style.display = 'block';
        statusEl.textContent = message;
        statusEl.style.color = type === 'error' ? 'var(--color-danger)' :
                               type === 'success' ? 'var(--color-success)' : 'var(--color-secondary)';
    }

    btnAutoFill.addEventListener('click', async function() {
        const symbol = symbolInput.value.trim().toUpperCase();
        if (!symbol) {
            showStatus('Masukkan symbol terlebih dahulu', 'error');
            return;
        }

        btnAutoFill.disabled = true;
        btnAutoFill.textContent = 'Loading...';
        showStatus('Mengambil data dari Binance Futures...', 'info');

        try {
            const response = await fetch(`/api/derivatives/live/${symbol}`);
            const result = await response.json();

            if (!result.success) {
                showStatus(result.message || 'Gagal mengambil data', 'error');
                return;
            }

            const d = result.data;

            // Auto-fill: Binance-only data (akurat)
            if (d.funding_rate !== null && d.funding_rate !== undefined) {
                document.getElementById('funding_rate').value = parseFloat(d.funding_rate * 100).toFixed(4);
            }
            if (d.ls_ratio_accounts) {
                document.getElementById('ls_ratio_accounts').value = parseFloat(d.ls_ratio_accounts).toFixed(4);
            }
            if (d.ls_ratio_top_traders) {
                document.getElementById('ls_ratio_top_traders').value = parseFloat(d.ls_ratio_top_traders).toFixed(4);
            }
            if (d.price_change !== undefined) {
                document.getElementById('price_change').value = parseFloat(d.price_change).toFixed(2);
            }

            // Update symbol to clean version
            symbolInput.value = d.symbol || symbol;

            // Highlight fields that need manual input
            const manualFields = ['ls_ratio_volume', 'open_interest_change', 'volume_change'];
            manualFields.forEach(id => {
                const el = document.getElementById(id);
                if (el && !el.value) {
                    el.style.border = '1px solid #f59e0b';
                    el.style.boxShadow = '0 0 6px rgba(245, 158, 11, 0.2)';
                }
            });

            let statusMsg = `🔄 Data Binance ${d.symbol} terisi`;
            if (d.current_price) {
                statusMsg += ` | $${parseFloat(d.current_price).toFixed(4)}`;
            }
            statusMsg += '\n📋 L/S Ratio (24h), OI%, Vol% → isi manual dari Coinglass';
            showStatus(statusMsg, 'success');

        } catch (err) {
            showStatus('Error: ' + err.message, 'error');
        } finally {
            btnAutoFill.disabled = false;
            btnAutoFill.textContent = 'Auto-Fill';
        }
    });

    // Enter key on symbol input triggers auto-fill
    symbolInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            btnAutoFill.click();
        }
    });
});
</script>
@endsection
