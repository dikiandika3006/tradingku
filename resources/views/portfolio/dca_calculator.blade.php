@extends('layouts.app')

@section('title', 'Kalkulator DCA & TP')

@section('content')
<div class="page-header">
    <div class="page-title">
        <h1>Kalkulator DCA &amp; Target TP</h1>
        <p>Simulasikan beli tambahan di level harga tertentu: lihat average price baru, harga take profit, dan potensi keuntungannya</p>
    </div>
</div>

<div class="stats-grid">
    <div class="glass-card">
        <div class="card-title"><span>Nilai Aset Aktif</span></div>
        <div class="stat-value text-success">${{ number_format($total_value, 2) }}</div>
        <div class="stat-desc">≈ Rp {{ number_format($total_value * $usdt_idr_rate, 0, ',', '.') }} · modal ${{ number_format($total_cost, 2) }}</div>
    </div>
    <div class="glass-card">
        <div class="card-title"><span>Total Rencana DCA</span></div>
        <div class="stat-value" style="color: var(--text-main);" id="ringkasDca">$0.00</div>
        <div class="stat-desc" id="ringkasDcaIdr">≈ Rp 0</div>
    </div>
    <div class="glass-card">
        <div class="card-title"><span>Total Potensi Profit di TP</span></div>
        <div class="stat-value text-success" id="ringkasProfit">$0.00</div>
        <div class="stat-desc" id="ringkasProfitIdr">≈ Rp 0</div>
    </div>
</div>

<div class="glass-card mb-6">
    <div class="dca-global">
        <div>
            <label for="tpGlobal" class="form-label" style="margin-bottom: 0.35rem;">Target profit untuk semua aset (%)</label>
            <div style="display: flex; gap: 0.5rem;">
                <input type="number" id="tpGlobal" class="form-control" step="any" min="0" value="20" style="max-width: 140px;">
                <button type="button" id="btnTerapkanTp" class="btn btn-secondary">Terapkan ke semua</button>
                <button type="button" id="btnReset" class="btn btn-secondary" title="Kosongkan semua level DCA">Reset</button>
            </div>
        </div>
        <div class="dca-catatan">
            Kurs: Rp {{ number_format($usdt_idr_rate, 0, ',', '.') }} / USDT ·
            Harga TP = average baru × (1 + target %). Fee bursa belum dihitung.
            Isian tersimpan di browser ini.
        </div>
    </div>
</div>

@if(empty($assets))
    <div class="glass-card" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
        Belum ada aset aktif bernilai di atas $2 untuk disimulasikan.
    </div>
@else
    <div class="dca-grid" id="dcaGrid">
        @foreach($assets as $a)
            <div class="glass-card dca-kartu" data-aset="{{ $a['asset'] }}">
                <div class="dca-kepala">
                    <div>
                        <div class="dca-nama">{{ $a['asset'] }}</div>
                        <div class="dca-sub">Harga pasar <span class="mono" data-harga-pasar></span></div>
                    </div>
                    <span class="badge {{ $a['pnl_percent'] >= 0 ? 'badge-success' : 'badge-danger' }}">
                        {{ $a['pnl_percent'] >= 0 ? '+' : '' }}{{ number_format($a['pnl_percent'], 2) }}%
                    </span>
                </div>

                <div class="dca-posisi">
                    <div><span>Saldo</span><b class="mono" data-saldo></b></div>
                    <div><span>Average</span><b class="mono" data-avg-lama></b></div>
                    <div><span>Modal</span><b class="mono">${{ number_format($a['cost'], 2) }}</b></div>
                    <div><span>Nilai</span><b class="mono">${{ number_format($a['value'], 2) }}</b></div>
                </div>

                <div class="dca-bagian">Rencana DCA</div>
                <div class="dca-level-judul">
                    <span>Harga beli (USDT)</span><span>Nominal ($)</span><span></span>
                </div>
                <div data-levels></div>
                <button type="button" class="btn btn-secondary btn-sm" data-tambah-level>+ Tambah level</button>

                <div class="dca-tp">
                    <label class="form-label" style="margin: 0;">Target profit (%)</label>
                    <input type="number" class="form-control" step="any" min="0" data-tp>
                </div>

                <div class="dca-hasil" data-hasil></div>
            </div>
        @endforeach
    </div>
@endif
@endsection

@section('scripts')
<script>
(function () {
    const ASET = @json($assets);
    const KURS = {{ json_encode((float) $usdt_idr_rate) }};
    const KUNCI = 'kalkulator-dca-v1';

    // ---------------- Perhitungan (murni, tanpa DOM) ----------------
    // posisi: {amount, cost, avg_price, price}; levels: [{harga, nominal}]; tp: persen
    function hitung(posisi, levels, tp) {
        let qtyTambah = 0, modalTambah = 0;
        for (const l of levels) {
            const h = Number(l.harga), n = Number(l.nominal);
            if (h > 0 && n > 0) { qtyTambah += n / h; modalTambah += n; }
        }
        const qtyBaru = posisi.amount + qtyTambah;
        const modalBaru = posisi.cost + modalTambah;
        const avgBaru = qtyBaru > 0 ? modalBaru / qtyBaru : 0;
        const p = Math.max(0, Number(tp) || 0) / 100;
        const hargaTp = avgBaru * (1 + p);
        const profit = qtyBaru * hargaTp - modalBaru;
        const hargaTpTanpaDca = posisi.avg_price * (1 + p);
        return {
            qtyTambah, modalTambah, qtyBaru, modalBaru, avgBaru, hargaTp, profit,
            profitIdr: profit * KURS,
            persen: modalBaru > 0 ? profit / modalBaru * 100 : 0,
            avgBerubah: posisi.avg_price > 0 ? (avgBaru / posisi.avg_price - 1) * 100 : 0,
            tpDariPasar: posisi.price > 0 ? (hargaTp / posisi.price - 1) * 100 : 0,
            hargaTpTanpaDca,
            profitTanpaDca: posisi.cost * p,
        };
    }
    window.hitungDca = hitung; // untuk pengujian di konsol

    // ---------------- Format angka ----------------
    const usd = (v) => '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const idr = (v) => 'Rp ' + Math.round(v).toLocaleString('id-ID');
    function harga(v) {
        const a = Math.abs(v);
        const d = a >= 1000 ? 2 : a >= 1 ? 4 : a >= 0.01 ? 6 : 8;
        return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
    }
    const jumlah = (v) => Number(v).toLocaleString('en-US', { maximumFractionDigits: 8 });
    const persen = (v, tanda = true) => (tanda && v > 0 ? '+' : '') + Number(v).toFixed(2) + '%';

    // ---------------- Penyimpanan per browser ----------------
    let simpanan = {};
    try { simpanan = JSON.parse(localStorage.getItem(KUNCI) || '{}') || {}; } catch (e) { simpanan = {}; }
    function simpan() { try { localStorage.setItem(KUNCI, JSON.stringify(simpanan)); } catch (e) {} }

    const tpGlobal = document.getElementById('tpGlobal');
    if (simpanan._tpGlobal !== undefined) tpGlobal.value = simpanan._tpGlobal;

    // ---------------- Kartu aset ----------------
    function barisLevel(kartu, l = {}) {
        const div = document.createElement('div');
        div.className = 'dca-level';
        div.innerHTML =
            '<input type="number" class="form-control" step="any" min="0" placeholder="harga" data-harga>' +
            '<input type="number" class="form-control" step="any" min="0" placeholder="0" data-nominal>' +
            '<button type="button" class="btn btn-secondary btn-sm" title="Hapus level" data-hapus>×</button>' +
            '<div class="dca-level-info" data-info></div>';
        div.querySelector('[data-harga]').value = l.harga ?? '';
        div.querySelector('[data-nominal]').value = l.nominal ?? '';
        kartu.querySelector('[data-levels]').appendChild(div);
        return div;
    }

    function bacaKartu(kartu) {
        const levels = [...kartu.querySelectorAll('.dca-level')].map(b => ({
            harga: b.querySelector('[data-harga]').value,
            nominal: b.querySelector('[data-nominal]').value,
        }));
        return { levels, tp: kartu.querySelector('[data-tp]').value };
    }

    function render(kartu) {
        const posisi = ASET.find(a => a.asset === kartu.dataset.aset);
        const isi = bacaKartu(kartu);
        const r = hitung(posisi, isi.levels, isi.tp);

        // keterangan per level: token dibeli & jarak dari harga pasar
        kartu.querySelectorAll('.dca-level').forEach((b, i) => {
            const h = Number(isi.levels[i].harga), n = Number(isi.levels[i].nominal);
            const info = b.querySelector('[data-info]');
            if (h > 0) {
                const jarak = (h / posisi.price - 1) * 100;
                info.textContent = (n > 0 ? '≈ ' + jumlah(n / h) + ' ' + posisi.asset + ' · ' : '') +
                    persen(jarak) + ' dari harga pasar';
                info.className = 'dca-level-info ' + (jarak > 0 ? 'peringatan' : '');
            } else {
                info.textContent = '';
            }
        });

        const adaDca = r.modalTambah > 0;
        const turun = r.avgBerubah < 0;
        kartu.querySelector('[data-hasil]').innerHTML = `
            <div class="dca-hasil-grid">
                <div><span>Average baru</span><b class="mono">${harga(r.avgBaru)}</b>
                    <small class="${adaDca ? (turun ? 'text-success' : 'text-danger') : ''}">${adaDca ? persen(r.avgBerubah) + ' dari ' + harga(posisi.avg_price) : 'belum ada DCA'}</small></div>
                <div><span>Harga TP</span><b class="mono">${harga(r.hargaTp)}</b>
                    <small>${persen(r.tpDariPasar)} dari harga pasar</small></div>
                <div><span>Potensi profit</span><b class="mono text-success">${usd(r.profit)}</b>
                    <small>≈ ${idr(r.profitIdr)}</small></div>
                <div><span>Profit (%)</span><b class="mono text-success">${persen(r.persen)}</b>
                    <small>dari modal ${usd(r.modalBaru)}</small></div>
            </div>
            ${adaDca ? `<div class="dca-banding">
                Beli tambahan <b>${jumlah(r.qtyTambah)} ${posisi.asset}</b> senilai ${usd(r.modalTambah)} → saldo ${jumlah(r.qtyBaru)}.
                Tanpa DCA, TP di ${harga(r.hargaTpTanpaDca)} dengan profit ${usd(r.profitTanpaDca)}.
            </div>` : ''}`;

        simpanan[posisi.asset] = isi;
        simpan();
        ringkas();
    }

    function ringkas() {
        let dca = 0, profit = 0;
        document.querySelectorAll('.dca-kartu').forEach(k => {
            const posisi = ASET.find(a => a.asset === k.dataset.aset);
            const isi = bacaKartu(k);
            const r = hitung(posisi, isi.levels, isi.tp);
            dca += r.modalTambah; profit += r.profit;
        });
        document.getElementById('ringkasDca').textContent = usd(dca);
        document.getElementById('ringkasDcaIdr').textContent = '≈ ' + idr(dca * KURS);
        document.getElementById('ringkasProfit').textContent = usd(profit);
        document.getElementById('ringkasProfitIdr').textContent = '≈ ' + idr(profit * KURS);
    }

    document.querySelectorAll('.dca-kartu').forEach(kartu => {
        const posisi = ASET.find(a => a.asset === kartu.dataset.aset);
        kartu.querySelector('[data-harga-pasar]').textContent = harga(posisi.price);
        kartu.querySelector('[data-saldo]').textContent = jumlah(posisi.amount);
        kartu.querySelector('[data-avg-lama]').textContent = harga(posisi.avg_price);

        const lama = simpanan[posisi.asset];
        const levels = lama && Array.isArray(lama.levels) && lama.levels.length ? lama.levels : [{}];
        levels.forEach(l => barisLevel(kartu, l));
        kartu.querySelector('[data-tp]').value = lama && lama.tp !== undefined && lama.tp !== '' ? lama.tp : tpGlobal.value;

        kartu.addEventListener('input', () => render(kartu));
        kartu.addEventListener('click', (e) => {
            if (e.target.matches('[data-hapus]')) {
                const baris = e.target.closest('.dca-level');
                if (kartu.querySelectorAll('.dca-level').length > 1) baris.remove();
                else baris.querySelectorAll('input').forEach(i => i.value = '');
                render(kartu);
            } else if (e.target.matches('[data-tambah-level]')) {
                barisLevel(kartu).querySelector('[data-harga]').focus();
                render(kartu);
            }
        });
        render(kartu);
    });

    tpGlobal.addEventListener('input', () => { simpanan._tpGlobal = tpGlobal.value; simpan(); });
    document.getElementById('btnTerapkanTp').addEventListener('click', () => {
        document.querySelectorAll('.dca-kartu').forEach(k => { k.querySelector('[data-tp]').value = tpGlobal.value; render(k); });
    });
    document.getElementById('btnReset').addEventListener('click', () => {
        if (!confirm('Kosongkan semua level DCA?')) return;
        document.querySelectorAll('.dca-kartu').forEach(k => {
            k.querySelector('[data-levels]').innerHTML = '';
            barisLevel(k);
            render(k);
        });
    });
})();
</script>
<style>
    .mono { font-family: monospace; }
    .dca-global { display: flex; justify-content: space-between; align-items: flex-end; gap: 1rem; flex-wrap: wrap; }
    .dca-catatan { font-size: 0.78rem; color: var(--text-muted); max-width: 520px; line-height: 1.5; }
    .dca-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1.25rem; }
    .dca-kartu { display: flex; flex-direction: column; gap: 0.75rem; }
    .dca-kepala { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; }
    .dca-nama { font-weight: 800; font-size: 1.2rem; color: var(--color-secondary); }
    .dca-sub { font-size: 0.78rem; color: var(--text-muted); margin-top: 0.15rem; }
    .dca-posisi { display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem 1rem; font-size: 0.82rem; }
    .dca-posisi div, .dca-hasil-grid div { display: flex; flex-direction: column; min-width: 0; }
    .dca-posisi span, .dca-hasil-grid span { color: var(--text-muted); font-size: 0.72rem; }
    .dca-posisi b { overflow-wrap: anywhere; }
    .dca-bagian { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); border-top: 1px solid var(--border-light); padding-top: 0.75rem; }
    .dca-level-judul, .dca-level { display: grid; grid-template-columns: 1fr 1fr 2.2rem; gap: 0.5rem; align-items: center; }
    .dca-level-judul { font-size: 0.7rem; color: var(--text-muted); margin-bottom: -0.35rem; }
    .dca-level { margin-bottom: 0.35rem; }
    .dca-level .form-control { padding: 0.5rem 0.6rem; min-width: 0; }
    .dca-level-info { grid-column: 1 / -1; font-size: 0.72rem; color: var(--text-muted); margin-top: -0.15rem; }
    .dca-level-info.peringatan { color: #f59e0b; }
    [data-tambah-level] { align-self: flex-start; }
    .dca-tp { display: grid; grid-template-columns: 1fr 120px; gap: 0.5rem; align-items: center; font-size: 0.85rem; }
    .dca-hasil { background: rgba(255,255,255,0.03); border: 1px solid var(--border-light); border-radius: 10px; padding: 0.85rem; }
    .dca-hasil-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem 1rem; }
    .dca-hasil-grid b { font-size: 1rem; overflow-wrap: anywhere; }
    .dca-hasil-grid small { font-size: 0.7rem; color: var(--text-muted); }
    .dca-hasil-grid small.text-success { color: #10b981; }
    .dca-hasil-grid small.text-danger { color: #ef4444; }
    .dca-banding { margin-top: 0.75rem; padding-top: 0.65rem; border-top: 1px dashed var(--border-light); font-size: 0.75rem; color: var(--text-muted); line-height: 1.5; }
    @media (max-width: 768px) {
        .dca-grid { grid-template-columns: 1fr; }
        .dca-global > div:first-child > div { flex-wrap: wrap; }
    }
</style>
@endsection
