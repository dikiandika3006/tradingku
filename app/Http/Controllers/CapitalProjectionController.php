<?php

namespace App\Http\Controllers;

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
}
