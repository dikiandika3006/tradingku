<?php

namespace App\Services;

use App\Models\MonthlyPortfolioSnapshot;
use App\Models\Setting;
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

        $aktual = $this->saldoNyataPerBulan();

        $rows = [];
        $kursor = Carbon::createFromFormat('Y-m-d', $mulai . '-01')->startOfMonth();
        $btcBerlaku = 50.0;   // dipakai sampai fase pertama ditemukan

        for ($i = 0; $i < $jumlahBulan; $i++) {
            $ym = $kursor->format('Y-m');

            // Fase alokasi berlaku sejak bulan yang disebut, sampai diganti fase berikutnya
            if (isset($fasesAlokasi[$ym])) {
                $btcBerlaku = max(0.0, min(100.0, $fasesAlokasi[$ym]));
            }

            $topUp  = ($topupDariKedua && $i === 0) ? 0.0 : $topupBulanan;
            $persen = $returnKhusus[$ym] ?? $returnDefault;

            $returnIdr  = $modal * $persen / 100;
            $saldoAkhir = $modal + $topUp + $returnIdr;

            $nyata = $aktual[$ym] ?? null;

            $rows[] = [
                'year_month'   => $ym,
                'month_name'   => $kursor->translatedFormat('F'),
                'year'         => $kursor->format('Y'),
                'modal_awal'   => $modal,
                'top_up'       => $topUp,
                'return_persen' => $persen,
                'return_idr'   => $returnIdr,
                'saldo_akhir'  => $saldoAkhir,
                'btc_persen'   => $btcBerlaku,
                'alt_persen'   => 100.0 - $btcBerlaku,
                'alokasi_btc'  => $saldoAkhir * $btcBerlaku / 100,
                'alokasi_alt'  => $saldoAkhir * (100.0 - $btcBerlaku) / 100,
                'aktual'       => $nyata,
                'selisih'      => $nyata !== null ? $nyata - $saldoAkhir : null,
                'selisih_persen' => ($nyata !== null && $saldoAkhir > 0)
                    ? (($nyata - $saldoAkhir) / $saldoAkhir) * 100
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
