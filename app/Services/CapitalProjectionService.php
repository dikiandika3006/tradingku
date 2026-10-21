<?php

namespace App\Services;

use App\Models\CapitalProjectionOverride;
use App\Models\MonthlyPortfolioSnapshot;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Proyeksi pertumbuhan modal bulanan, beserta perbandingannya dengan saldo nyata.
 *
 * Rumusnya mengikuti model yang dipakai user:
 *   Return       = Modal Awal x return%      (top-up tidak ikut dibungakan bulan itu)
 *   Saldo Akhir  = Modal Awal + Top Up + Return
 *   Modal bulan berikutnya = Saldo Akhir bulan ini
 *
 * Saldo nyata diambil dari snapshot bulanan yang sudah ada, jadi kolom aktual
 * hanya terisi untuk bulan yang benar-benar sudah terjadi.
 */
class CapitalProjectionService
{
    /** Nilai bawaan bila pengaturan belum pernah diisi. */
    public const DEFAULTS = [
        'projection_start_month'        => '2026-09',
        'projection_start_capital'      => '22000000',
        'projection_monthly_topup'      => '3000000',
        'projection_topup_from_second'  => '1',
        'projection_default_return'     => '3.00',
        'projection_months'             => '28',
        'projection_return_overrides'   => "2026-09: 9.80",
        'projection_allocation_phases'  => "2026-09: 20\n2026-10: 30\n2026-11: 40\n2026-12: 50\n2028-01: 60",
    ];

    /** Semua pengaturan proyeksi, sudah diisi nilai bawaan bila kosong. */
    /** Mata uang kas yang tidak dihitung sebagai alokasi BTC maupun altcoin. */
    protected const KAS = ['USDT', 'BIDR', 'IDRT', 'BUSD', 'USDC', 'IDR'];

    protected $tokocrypto;

    public function __construct(TokocryptoService $tokocrypto)
    {
        $this->tokocrypto = $tokocrypto;
    }

    public function settings(): array
    {
        $tersimpan = Setting::whereIn('key', array_keys(self::DEFAULTS))
            ->pluck('value', 'key')
            ->all();

        $hasil = [];
        foreach (self::DEFAULTS as $kunci => $bawaan) {
            $nilai = $tersimpan[$kunci] ?? null;
            $hasil[$kunci] = ($nilai === null || $nilai === '') ? $bawaan : $nilai;
        }

        return $hasil;
    }

    /**
     * Susun tabel proyeksi.
     *
     * @return array{rows: array<int, array>, settings: array, summary: array}
     */
    public function build(): array
    {
        $s = $this->settings();

        $mulai        = $this->bulanValid($s['projection_start_month']);
        $modal        = (float)$s['projection_start_capital'];
        $topupBulanan = (float)$s['projection_monthly_topup'];
        $topupDariKedua = $s['projection_topup_from_second'] === '1';
        $returnDefault  = (float)$s['projection_default_return'];
        $jumlahBulan    = max(1, min(240, (int)$s['projection_months']));

        $returnKhusus = $this->parsePerBulan($s['projection_return_overrides']);
        $fasesAlokasi = $this->parsePerBulan($s['projection_allocation_phases']);

        $aktual  = $this->saldoNyataPerBulan();
        $bulanIni = now()->format('Y-m');
        $live     = $this->portofolioSekarang();
        $timpaan = CapitalProjectionOverride::get()->keyBy('year_month');

        $rows = [];
        $kursor = Carbon::createFromFormat('Y-m-d', $mulai . '-01')->startOfMonth();
        $btcBerlaku = 50.0;   // dipakai sampai fase pertama ditemukan

        for ($i = 0; $i < $jumlahBulan; $i++) {
            $ym = $kursor->format('Y-m');

            // Fase alokasi berlaku sejak bulan yang disebut, sampai diganti fase berikutnya
            if (isset($fasesAlokasi[$ym])) {
                $btcBerlaku = max(0.0, min(100.0, $fasesAlokasi[$ym]));
            }

            $o = $timpaan->get($ym);

            // Isian manual per baris selalu menang. null berarti "hitung otomatis",
            // sehingga nilai nol yang disengaja tetap bisa dibedakan dari kosong.
            $topUp = $o && $o->top_up !== null
                ? (float)$o->top_up
                : (($topupDariKedua && $i === 0) ? 0.0 : $topupBulanan);

            $returnIdr = $o && $o->return_idr !== null
                ? (float)$o->return_idr
                : $modal * ($returnKhusus[$ym] ?? $returnDefault) / 100;

            // Persen selalu diturunkan dari nominalnya, supaya kedua kolom tidak
            // pernah saling bertentangan saat nominalnya ditimpa manual.
            $persen = $modal > 0 ? ($returnIdr / $modal) * 100 : 0.0;

            $saldoAkhir = $modal + $topUp + $returnIdr;

            $alokasiBtc = $o && $o->alokasi_btc !== null
                ? (float)$o->alokasi_btc
                : $saldoAkhir * $btcBerlaku / 100;

            // Alt mengikuti sisa saldo, jadi menimpa BTC saja sudah cukup
            $alokasiAlt = $o && $o->alokasi_alt !== null
                ? (float)$o->alokasi_alt
                : $saldoAkhir - $alokasiBtc;

            $persenBtc = $saldoAkhir > 0 ? ($alokasiBtc / $saldoAkhir) * 100 : 0.0;
            $persenAlt = $saldoAkhir > 0 ? ($alokasiAlt / $saldoAkhir) * 100 : 0.0;

            $nyata = $aktual[$ym] ?? null;
            $dariPortofolio = false;
            $kasIdr = null;

            // Bulan berjalan memakai saldo dan isi portofolio saat ini, bukan
            // rencana. Bulan lampau tetap dari snapshot; rincian BTC/altcoin-nya
            // memang tidak pernah direkam, jadi kolom alokasinya tetap rencana.
            if ($ym === $bulanIni && $live !== null) {
                $nyata          = $live['total_idr'];
                $alokasiBtc     = $live['btc_idr'];
                $alokasiAlt     = $live['alt_idr'];
                $kasIdr         = $live['kas_idr'];
                $dariPortofolio = true;

                // Persen relatif terhadap saldo nyata, jadi BTC + Alt + Kas = 100%
                $persenBtc = $nyata > 0 ? ($alokasiBtc / $nyata) * 100 : 0.0;
                $persenAlt = $nyata > 0 ? ($alokasiAlt / $nyata) * 100 : 0.0;
            }

            $rows[] = [
                'year_month'   => $ym,
                'month_name'   => $kursor->translatedFormat('F'),
                'year'         => $kursor->format('Y'),
                'modal_awal'   => $modal,
                'top_up'       => $topUp,
                'return_persen' => $persen,
                'return_idr'   => $returnIdr,
                'saldo_akhir'  => $saldoAkhir,
                'btc_persen'   => $persenBtc,
                'alt_persen'   => $persenAlt,
                'alokasi_btc'  => $alokasiBtc,
                'alokasi_alt'  => $alokasiAlt,
                // Penanda kolom mana yang disetel tangan, untuk ditandai di layar
                'ditimpa'      => [
                    'top_up'      => $o && $o->top_up !== null,
                    'return_idr'  => $o && $o->return_idr !== null,
                    'alokasi_btc' => $o && $o->alokasi_btc !== null,
                    'alokasi_alt' => $o && $o->alokasi_alt !== null,
                ],
                // Alokasi yang tidak menjumlah ke saldo akhir perlu terlihat
                // Tidak berlaku untuk alokasi nyata: sisanya memang kas yang belum dibelikan
                'alokasi_timpang' => !$dariPortofolio && abs(($alokasiBtc + $alokasiAlt) - $saldoAkhir) > 1.0,
                'alokasi_dari_portofolio' => $dariPortofolio,
                'kas_idr'      => $kasIdr,
                'aktual'       => $nyata,
                // Selisih = Saldo Akhir rencana - Aktual.
                // Positif berarti saldo nyata masih KURANG dari rencana.
                'selisih'      => $nyata !== null ? $saldoAkhir - $nyata : null,
                'selisih_persen' => ($nyata !== null && $saldoAkhir > 0)
                    ? (($saldoAkhir - $nyata) / $saldoAkhir) * 100
                    : null,
                'sudah_lewat'  => $nyata !== null,
            ];

            $modal = $saldoAkhir;
            $kursor->addMonth();
        }

        return [
            'rows'     => $rows,
            'settings' => $s,
            'summary'  => $this->ringkasan($rows, (float)$s['projection_start_capital']),
        ];
    }

    /** @param array<int, array> $rows */
    protected function ringkasan(array $rows, float $modalAwal): array
    {
        $terakhir = end($rows) ?: null;
        $terbanding = array_values(array_filter($rows, fn($r) => $r['aktual'] !== null));
        $mutakhir = end($terbanding) ?: null;

        $totalTopUp = array_sum(array_column($rows, 'top_up'));
        $saldoAkhir = $terakhir['saldo_akhir'] ?? 0.0;

        return [
            'modal_awal'     => $modalAwal,
            'total_top_up'   => $totalTopUp,
            'total_setoran'  => $modalAwal + $totalTopUp,
            'saldo_proyeksi' => $saldoAkhir,
            'total_return'   => $saldoAkhir - $modalAwal - $totalTopUp,
            'bulan_terakhir' => $terakhir['year_month'] ?? null,
            'bulan_dibanding' => count($terbanding),
            'posisi_terkini' => $mutakhir,
        ];
    }

    /**
     * Isi portofolio saat ini dalam Rupiah: total, porsi BTC, altcoin, dan kas.
     *
     * Total dihitung sama persis dengan kartu "Nilai Portofolio Saat Ini" di
     * halaman Portofolio, supaya angka Aktual di sini tidak pernah berbeda dari
     * yang Anda lihat di sana. Null bila portofolio gagal diambil — bulan
     * berjalan lalu jatuh ke snapshot harian.
     */
    protected function portofolioSekarang(): ?array
    {
        try {
            $p = $this->tokocrypto->getPortfolio();
        } catch (\Throwable $e) {
            Log::warning('Proyeksi modal: portofolio gagal diambil: ' . $e->getMessage());
            return null;
        }

        $aset = $p['assets'] ?? [];
        $kurs = (float)($p['usdt_idr_rate'] ?? 0);

        if (empty($aset) || $kurs <= 0) {
            return null;
        }

        $total = 0.0; $btc = 0.0; $alt = 0.0; $kas = 0.0;

        foreach ($aset as $a) {
            $nama  = strtoupper((string)($a['asset'] ?? ''));
            $nilai = (float)($a['value_usdt'] ?? 0);

            $total += $nilai;

            if ($nama === 'BTC') {
                $btc += $nilai;
            } elseif (in_array($nama, self::KAS, true)) {
                $kas += $nilai;
            } elseif ($nilai > 0) {
                $alt += $nilai;
            }
        }

        return [
            'total_idr' => $total * $kurs,
            'btc_idr'   => $btc * $kurs,
            'alt_idr'   => $alt * $kurs,
            'kas_idr'   => $kas * $kurs,
        ];
    }

    /**
     * Saldo nyata akhir bulan dari snapshot portofolio, dalam Rupiah.
     *
     * @return array<string, float>
     */
    protected function saldoNyataPerBulan(): array
    {
        return MonthlyPortfolioSnapshot::query()
            ->pluck('ending_valuation_idr', 'year_month')
            ->map(fn($v) => (float)$v)
            ->all();
    }

    /**
     * Baca daftar "YYYY-MM: angka" per baris.
     *
     * Baris yang tidak berbentuk begitu diabaikan, supaya salah ketik satu baris
     * tidak menggagalkan seluruh proyeksi.
     *
     * @return array<string, float>
     */
    protected function parsePerBulan(?string $teks): array
    {
        $hasil = [];

        foreach (preg_split('/\r?\n/', (string)$teks) as $baris) {
            if (preg_match('/^\s*(\d{4}-\d{2})\s*[:=]\s*(-?[\d.,]+)\s*$/', $baris, $m)) {
                $hasil[$m[1]] = (float)str_replace(',', '.', $m[2]);
            }
        }

        return $hasil;
    }

    protected function bulanValid(?string $ym): string
    {
        return preg_match('/^\d{4}-\d{2}$/', (string)$ym)
            ? $ym
            : self::DEFAULTS['projection_start_month'];
    }
}
