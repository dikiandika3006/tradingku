{{-- Isi Investor Report. Dipakai bersama oleh halaman tampil dan PDF (dompdf), jadi
     hanya memakai tabel & CSS sederhana — tanpa flexbox/grid. Grafik berupa SVG
     yang dirender di server (App\Support\SvgChart). --}}
@php
    $rp = fn ($v) => 'Rp' . number_format(round($v), 0, ',', '.');
    $rpSigned = fn ($v) => ($v < 0 ? '-' : '+') . 'Rp' . number_format(abs(round($v)), 0, ',', '.');
    $rpMinus = fn ($v) => ($v < 0 ? '-' : '') . 'Rp' . number_format(abs(round($v)), 0, ',', '.');
    $pct = fn ($v) => number_format($v, 2, ',', '.') . '%';
    $pctSigned = fn ($v) => ($v < 0 ? '' : '+') . number_format($v, 2, ',', '.') . '%';
    $usd = fn ($v) => '$' . number_format($v, 0, ',', '.');
    $juta = fn ($v) => rtrim(rtrim(number_format($v / 1e6, 2, ',', '.'), '0'), ',') . ' Juta';
    $tgl = fn ($d) => $d->locale('id')->translatedFormat('d F Y');
    $tglPendek = fn ($d) => $d->locale('id')->translatedFormat('d M y');
    $tone = fn ($v) => $v < 0 ? 'ir-neg' : 'ir-pos';

    $start = $report->period_start;
    $end = $report->period_end;
    $periodeLabel = ($start->year === $end->year
            ? $start->locale('id')->translatedFormat('d F')
            : $tgl($start)) . ' – ' . $tgl($end);

    // Catatan: teks biasa, baris kosong = paragraf baru, **teks** = tebal.
    $paragraf = function (?string $text) {
        return collect(preg_split('/\R{2,}/', trim((string)$text)))
            ->filter(fn ($p) => trim($p) !== '')
            ->map(fn ($p) => preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', nl2br(e(trim($p)))));
    };
@endphp

<style>
    .ir-doc { font-family: 'DejaVu Sans', 'Noto Sans', 'Segoe UI', Arial, sans-serif; color: #111; font-size: 10pt; line-height: 1.35; }
    .ir-doc h1, .ir-doc h2, .ir-doc h3, .ir-doc p, .ir-doc td, .ir-doc th, .ir-doc strong { color: #111; background: none; }
    .ir-doc h1 { font-size: 22pt; margin: 0 0 14px; letter-spacing: 0.5px; }
    .ir-doc .ir-sub { font-weight: bold; margin: 0 0 10px; font-size: 10.5pt; }
    .ir-doc .ir-periode { font-weight: bold; font-size: 9.5pt; margin: 0; }
    .ir-doc hr { border: 0; border-top: 1px solid #c8c8c8; margin: 16px 0; }
    .ir-doc h2 { font-size: 14pt; margin: 0 0 14px; padding-left: 8px; border-left: 4px solid #1d4ed8; }
    .ir-doc h3 { font-size: 10.5pt; margin: 14px 0 10px; padding-left: 8px; border-left: 4px solid #16a34a; }
    .ir-doc p { margin: 0 0 10px; }
    .ir-doc table.ir-table { border-collapse: collapse; margin: 0 auto 14px; }
    .ir-doc table.ir-table th { font-weight: normal; text-align: right; padding: 5px 10px; border-bottom: 1.5px solid #111; }
    .ir-doc table.ir-table th:first-child { text-align: left; }
    .ir-doc table.ir-table td { padding: 5px 10px; border-bottom: 1px solid #d4d4d4; text-align: right; }
    .ir-doc table.ir-table td:first-child { text-align: left; }
    .ir-doc table.ir-table tr.ir-total td { font-weight: bold; }
    .ir-doc table.ir-layout { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .ir-doc td.ir-cell { vertical-align: middle; padding: 0; border: 0; }
    .ir-doc .ir-nowrap { white-space: nowrap; }
    .ir-doc .ir-swatch { display: inline-block; width: 9px; height: 9px; margin-right: 6px; }
    .ir-doc .ir-big { font-size: 22pt; font-weight: bold; margin: 6px 0 12px; }
    .ir-doc .ir-pos { color: #15803d; }
    .ir-doc .ir-neg { color: #b91c1c; }
    .ir-doc .ir-muted { color: #6b7280; font-size: 8.5pt; }
    .ir-doc .ir-legend { font-size: 8.5pt; color: #6b7280; text-align: center; margin: 0 0 12px; }
    .ir-doc .ir-chart { width: 100%; max-width: 640px; }
    .ir-doc .ir-keep { page-break-inside: avoid; }
</style>

<div class="ir-doc">
    <h1>{{ $fund_name }}</h1>
    <p class="ir-sub">INVESTOR REPORT</p>
    <p class="ir-periode">Periode {{ $periodeLabel }}</p>

    <hr>

    {{-- ================= PERKEMBANGAN DANA ================= --}}
    <div class="ir-keep">
    <h2>PERKEMBANGAN DANA</h2>
    <table class="ir-table">
        <thead>
            <tr>
                <th></th>
                <th>{{ mb_strtoupper($tgl($start)) }}</th>
                <th>{{ mb_strtoupper($tgl($end)) }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Total Modal</strong></td>
                <td>{{ $rp($awal['modal']) }}</td>
                <td><strong>{{ $rp($akhir['modal']) }}</strong></td>
            </tr>
            <tr>
                <td><strong>Nilai Portfolio</strong></td>
                <td>{{ $rp($awal['nilai']) }}</td>
                <td><strong>{{ $rp($akhir['nilai']) }}</strong></td>
            </tr>
            <tr>
                <td><strong>Profit</strong></td>
                <td>{{ $rpMinus($awal['profit']) }}</td>
                <td><strong>{{ $rpMinus($akhir['profit']) }}</strong></td>
            </tr>
            <tr>
                <td><strong>Return</strong></td>
                <td>{{ $pct($awal['return']) }}</td>
                <td><strong>{{ $pct($akhir['return']) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <h3>PORTFOLIO SAAT INI</h3>
    <div class="ir-big">{{ $rp($akhir['nilai']) }}</div>
    <p><strong>{{ $akhir['profit'] < 0 ? 'Rugi' : 'Profit' }} saat ini: <span class="{{ $tone($akhir['profit']) }}">{{ $rpMinus($akhir['profit']) }}</span></strong></p>
    <p><strong>Return: <span class="{{ $tone($akhir['return']) }}">{{ $pctSigned($akhir['return']) }}</span></strong></p>
    </div>

    @if(!empty($charts['growth']))
        <div class="ir-keep">
        <h3>Nilai Portfolio vs Total Modal</h3>
        <div style="text-align: center;"><img src="{{ $charts['growth'] }}" class="ir-chart" alt="Grafik nilai portfolio"></div>
        <p class="ir-legend">
            <span class="ir-swatch" style="background: #2563eb;"></span>Nilai Portfolio
            &nbsp;&nbsp;&nbsp;
            <span class="ir-swatch" style="background: #9ca3af;"></span>Total Modal
        </p>
        </div>
    @endif

    <hr>

    {{-- ================= KINERJA PERIODE ================= --}}
    @isset($trading)
    <div class="ir-keep">
    <h2>KINERJA PERIODE INI</h2>
    <p>
        Di luar tambahan modal, hasil pengelolaan dana selama periode ini adalah
        <strong class="{{ $tone($periode['gain']) }}">{{ $rpSigned($periode['gain']) }}</strong>
        atau <strong class="{{ $tone($periode['return']) }}">{{ $pctSigned($periode['return']) }}</strong>.
    </p>
    <table class="ir-table">
        <tbody>
            <tr><td>Hasil pengelolaan periode ini</td><td><strong class="{{ $tone($periode['gain']) }}">{{ $rpSigned($periode['gain']) }}</strong></td></tr>
            <tr><td>Return periode ini</td><td><strong class="{{ $tone($periode['return']) }}">{{ $pctSigned($periode['return']) }}</strong></td></tr>
            @if($benchmark)
                <tr><td>Pembanding: BTC ({{ $usd($benchmark['start']) }} &rarr; {{ $usd($benchmark['end']) }})</td><td><strong class="{{ $tone($benchmark['return']) }}">{{ $pctSigned($benchmark['return']) }}</strong></td></tr>
            @endif
            <tr><td>Posisi ditutup (jual)</td><td>{{ $trading['count'] }} transaksi</td></tr>
            @if($trading['count'] > 0)
                <tr><td>Win rate</td><td>{{ $pct($trading['win_rate']) }} ({{ $trading['wins'] }} profit / {{ $trading['losses'] }} rugi)</td></tr>
                <tr><td>Profit dari posisi yang ditutup</td><td><strong class="{{ $tone($trading['realized_idr']) }}">{{ $rpSigned($trading['realized_idr']) }}</strong></td></tr>
            @endif
        </tbody>
    </table>
    @if($benchmark)
        <p>
            @if($periode['return'] >= $benchmark['return'])
                Kinerja portfolio <strong>lebih baik</strong> dibanding BTC pada periode yang sama.
            @else
                Kinerja portfolio masih di bawah BTC pada periode yang sama.
            @endif
        </p>
    @endif
    </div>

    @if($trading['best']->isNotEmpty() || $trading['worst']->isNotEmpty())
        <div class="ir-keep">
        <h3>Aset Penyumbang Hasil Terbesar</h3>
        <table class="ir-table">
            <thead><tr><th>Asset</th><th>Hasil (posisi ditutup)</th></tr></thead>
            <tbody>
                @foreach($trading['best']->concat($trading['worst']) as $row)
                    <tr><td>{{ $row['asset'] }}</td><td class="{{ $tone($row['pnl_idr']) }}">{{ $rpSigned($row['pnl_idr']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif

    <hr>
    @endisset

    {{-- ================= PERKEMBANGAN MODAL ================= --}}
    <div class="ir-keep">
    <h2>PERKEMBANGAN MODAL</h2>
    @if($tambahan > 0)
        <p>Modal investor bertambah selama periode berjalan.</p>
    @elseif($tambahan < 0)
        <p>Modal investor berkurang selama periode berjalan karena ada penarikan dana.</p>
    @else
        <p>Tidak ada perubahan modal investor selama periode berjalan.</p>
    @endif
    <p><strong>Rp{{ $juta($awal['modal']) }} &rarr; Rp{{ $juta($akhir['modal']) }}</strong></p>
    @if($tambahan != 0)
        <p><strong>{{ $tambahan > 0 ? 'Tambahan modal' : 'Penarikan modal' }}: {{ $rpSigned($tambahan) }}</strong></p>
        @if($tambahan > 0)
            <p>Kenaikan nilai portfolio tidak seluruhnya merupakan profit, karena terdapat tambahan modal sebesar Rp{{ mb_strtolower($juta($tambahan)) }} selama periode ini.</p>
        @else
            <p>Penurunan nilai portfolio tidak seluruhnya merupakan kerugian, karena terdapat penarikan modal sebesar Rp{{ mb_strtolower($juta(abs($tambahan))) }} selama periode ini.</p>
        @endif
    @else
        <p>Seluruh perubahan nilai portfolio pada periode ini berasal dari hasil pengelolaan dana.</p>
    @endif
    </div>

    <hr>

    {{-- ================= POSISI INVESTOR ================= --}}
    <div class="ir-keep">
    <h2>POSISI INVESTOR</h2>
    <table class="ir-layout">
        <tr>
            @if(!empty($charts['investors']))
                <td class="ir-cell" style="width: 190px; text-align: center;"><img src="{{ $charts['investors'] }}" width="170" height="170" alt="Porsi investor"></td>
            @endif
            <td class="ir-cell">
                <table class="ir-table">
                    <thead>
                        <tr><th>Investor</th><th>Modal Saat Ini</th><th>Porsi</th></tr>
                    </thead>
                    <tbody>
                        @foreach($investors as $inv)
                            <tr>
                                <td><span class="ir-swatch" style="background: {{ $inv['color'] }};"></span><strong>{{ $inv['name'] }}</strong></td>
                                <td>{{ $rp($inv['akhir']) }}</td>
                                <td>{{ $pct($inv['porsi']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="ir-total">
                            <td>TOTAL</td>
                            <td>{{ $rp($akhir['modal']) }}</td>
                            <td>100%</td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>
    </div>

    <div class="ir-keep">
    <h3>Nilai & Profit Masing-Masing Investor</h3>
    <table class="ir-table">
        <thead>
            <tr><th>Investor</th><th>Modal</th><th>Nilai Saat Ini</th><th>Profit</th><th>Return</th></tr>
        </thead>
        <tbody>
            @foreach($investors as $inv)
                <tr>
                    <td>{{ $inv['name'] }}</td>
                    <td>{{ $rp($inv['akhir']) }}</td>
                    <td><strong>{{ $rp($inv['nilai']) }}</strong></td>
                    <td class="{{ $tone($inv['profit']) }}">{{ $rpSigned($inv['profit']) }}</td>
                    <td class="{{ $tone($inv['return']) }}">{{ $pctSigned($inv['return']) }}</td>
                </tr>
            @endforeach
            <tr class="ir-total">
                <td>TOTAL</td>
                <td>{{ $rp($akhir['modal']) }}</td>
                <td>{{ $rp($akhir['nilai']) }}</td>
                <td>{{ $rpSigned($akhir['profit']) }}</td>
                <td>{{ $pctSigned($akhir['return']) }}</td>
            </tr>
        </tbody>
    </table>
    <p class="ir-muted">
        Hasil dibagi secara time-weighted: setoran baru hanya ikut menanggung hasil sejak dananya masuk,
        sehingga investor yang top up belakangan tidak ikut menikmati atau menanggung hasil sebelumnya.
    </p>
    </div>

    <div class="ir-keep">
    <h3>Perkembangan Modal Masing-Masing Investor</h3>
    <table class="ir-table">
        <thead>
            <tr><th>Investor</th><th>Modal Awal</th><th>Modal Saat Ini</th><th>Perubahan Modal</th></tr>
        </thead>
        <tbody>
            @foreach($investors as $inv)
                <tr>
                    <td>{{ $inv['name'] }}</td>
                    <td>{{ $rp($inv['awal']) }}</td>
                    <td><strong>{{ $rp($inv['akhir']) }}</strong></td>
                    <td>{{ $inv['perubahan'] == 0 ? 'Rp0' : $rpSigned($inv['perubahan']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>

    {{-- ================= KONDISI PORTFOLIO ================= --}}
    @if($allocation->isNotEmpty())
        <hr>

        <div class="ir-keep">
        <h2>KONDISI PORTFOLIO</h2>
        <p>Saat ini portfolio lebih banyak berada dalam posisi <strong>{{ $top_asset['asset'] }}</strong>, yaitu sekitar <strong>{{ $pct($top_asset['percent']) }}</strong>.
            @if($porsi_kas > 0 && !in_array($top_asset['asset'], ['USDT', 'USDC', 'BUSD', 'FDUSD', 'IDR'], true))
                Porsi kas (stablecoin) sebesar <strong>{{ $pct($porsi_kas) }}</strong>.
            @endif
        </p>
        <table class="ir-layout">
            <tr>
                @if(!empty($charts['allocation']))
                    <td class="ir-cell" style="width: 190px; text-align: center;"><img src="{{ $charts['allocation'] }}" width="170" height="170" alt="Komposisi aset"></td>
                @endif
                <td class="ir-cell">
                    <table class="ir-table">
                        <thead>
                            <tr><th>Asset</th><th>Porsi</th></tr>
                        </thead>
                        <tbody>
                            @foreach($allocation as $i => $a)
                                <tr>
                                    <td><span class="ir-swatch" style="background: {{ $a['color'] }};"></span>{{ $a['asset'] }}</td>
                                    <td>{!! $i === 0 ? '<strong>' . e($pct($a['percent'])) . '</strong>' : e($pct($a['percent'])) !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </td>
            </tr>
        </table>
        @foreach($paragraf($report->portfolio_note) as $p)
            <p>{!! $p !!}</p>
        @endforeach
        </div>
    @endif

    {{-- ================= RIWAYAT RETURN ================= --}}
    @if(isset($history) && $history->count() > 1)
        <hr>

        <div class="ir-keep">
        <h2>RIWAYAT KINERJA</h2>
        <table class="ir-table">
            <thead>
                <tr><th>Periode</th><th>Total Modal</th><th>Nilai</th><th>Return Periode</th><th>Kumulatif</th></tr>
            </thead>
            <tbody>
                @foreach($history as $h)
                    <tr>
                        <td class="ir-nowrap">{{ $tglPendek($h['report']->period_start) }} – {{ $tglPendek($h['report']->period_end) }}</td>
                        <td>{{ $rp($h['modal']) }}</td>
                        <td>{{ $rp($h['nilai']) }}</td>
                        <td class="{{ $tone($h['return']) }}">{{ $pctSigned($h['return']) }}</td>
                        <td class="{{ $tone($h['cumulative']) }}">{{ $pctSigned($h['cumulative']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif

    {{-- ================= CATATAN PENGELOLA ================= --}}
    @if(trim((string)$report->manager_notes) !== '')
        <hr>

        <div class="ir-keep">
        <h2>CATATAN PENGELOLA</h2>
        @foreach($paragraf($report->manager_notes) as $p)
            <p>{!! $p !!}</p>
        @endforeach
        </div>
    @endif

    <hr>

    <div class="ir-keep">
        <p style="margin-bottom: 8px;"><strong>{{ $fund_name }}</strong></p>
        <p style="margin: 0;"><strong>Total Modal:</strong> {{ $rp($akhir['modal']) }}</p>
        <p style="margin: 0;"><strong>Nilai Portfolio:</strong> {{ $rp($akhir['nilai']) }}</p>
        <p style="margin: 0;"><strong>{{ $akhir['profit'] < 0 ? 'Rugi' : 'Profit' }}: {{ $rpMinus($akhir['profit']) }}</strong></p>
        <p><strong>Return: {{ $pctSigned($akhir['return']) }}</strong></p>
        <p><em>Report per {{ $tgl($end) }}</em></p>
    </div>
</div>
