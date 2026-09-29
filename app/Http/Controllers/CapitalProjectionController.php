<?php

namespace App\Http\Controllers;

use App\Models\CapitalProjectionOverride;
use App\Models\Setting;
use App\Services\CapitalProjectionService;
use Illuminate\Http\Request;

/**
 * Proyeksi pertumbuhan modal bulanan beserta perbandingannya dengan saldo nyata.
 */
class CapitalProjectionController extends Controller
{
    protected $projection;

    public function __construct(CapitalProjectionService $projection)
    {
        $this->projection = $projection;
    }

    public function index()
    {
        return view('portfolio.capital_projection', $this->projection->build());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'projection_start_month'       => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'projection_start_capital'     => 'required|numeric|min:0',
            'projection_monthly_topup'     => 'required|numeric|min:0',
            'projection_default_return'    => 'required|numeric|min:-100|max:1000',
            'projection_months'            => 'required|integer|min:1|max:240',
            'projection_return_overrides'  => 'nullable|string|max:4000',
            'projection_allocation_phases' => 'nullable|string|max:4000',
        ], [
            'projection_start_month.regex' => 'Bulan mulai harus berbentuk YYYY-MM, contoh 2026-09.',
        ]);

        // Checkbox tidak terkirim saat tidak dicentang
        $data['projection_topup_from_second'] = $request->boolean('projection_topup_from_second') ? '1' : '0';

        foreach ($data as $kunci => $nilai) {
            Setting::updateOrCreate(['key' => $kunci], ['value' => trim((string)$nilai)]);
        }

        return redirect()
            ->route('portfolio.capital-projection')
            ->with('success', 'Asumsi proyeksi berhasil diperbarui.');
    }

    /**
     * Simpan nilai nominal yang disetel tangan per baris.
     *
     * Kolom yang dikosongkan berarti "kembali ke hitungan otomatis", bukan nol —
     * karena itu isian kosong disimpan sebagai null, dan baris yang seluruh
     * kolomnya kosong dihapus supaya tabel timpaan tidak menumpuk baris hampa.
     */
    public function updateRows(Request $request)
    {
        $baris = $request->input('rows', []);

        if (!is_array($baris)) {
            return back()->with('error', 'Data baris tidak terbaca.');
        }

        $diubah = 0;

        foreach ($baris as $ym => $kolom) {
            if (!preg_match('/^\d{4}-\d{2}$/', (string)$ym) || !is_array($kolom)) {
                continue;
            }

            $nilai = [];
            foreach (CapitalProjectionOverride::FIELDS as $f) {
                $mentah = $kolom[$f] ?? '';
                $mentah = is_string($mentah) ? trim($mentah) : $mentah;

                // Buang pemisah ribuan supaya "1.000.000" dan "1000000" sama-sama diterima
                if (is_string($mentah) && $mentah !== '') {
                    $mentah = str_replace(['.', ' '], '', $mentah);
                    $mentah = str_replace(',', '.', $mentah);
                }

                $nilai[$f] = ($mentah === '' || $mentah === null || !is_numeric($mentah))
                    ? null
                    : (float)$mentah;
            }

            $adaIsi = count(array_filter($nilai, fn($v) => $v !== null)) > 0;

            if ($adaIsi) {
                CapitalProjectionOverride::updateOrCreate(['year_month' => $ym], $nilai);
                $diubah++;
            } else {
                CapitalProjectionOverride::where('year_month', $ym)->delete();
            }
        }

        return redirect()
            ->route('portfolio.capital-projection')
            ->with('success', $diubah > 0
                ? "{$diubah} baris disetel manual. Bulan sesudahnya ikut menyesuaikan."
                : 'Semua baris dikembalikan ke hitungan otomatis.');
    }

    /** Hapus seluruh isian manual, kembalikan ke hitungan otomatis. */
    public function resetRows()
    {
        $n = CapitalProjectionOverride::count();
        CapitalProjectionOverride::query()->delete();

        return redirect()
            ->route('portfolio.capital-projection')
            ->with('success', "{$n} baris manual dihapus, proyeksi kembali dihitung otomatis.");
    }
}
