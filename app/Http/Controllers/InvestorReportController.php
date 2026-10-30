<?php

namespace App\Http\Controllers;

use App\Models\InvestorReport;
use App\Services\InvestorReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvestorReportController extends Controller
{
    public function __construct(protected InvestorReportService $service)
    {
    }

    public function index()
    {
        $reports = InvestorReport::orderByDesc('period_end')->orderByDesc('id')->get()
            ->map(fn ($r) => $this->service->build($r, false));

        return view('investor_report.index', ['reports' => $reports]);
    }

    public function create()
    {
        return view('investor_report.form', [
            'report'   => null,
            'defaults' => $this->service->defaultPeriod(),
        ]);
    }

    public function store(Request $request)
    {
        $report = InvestorReport::create($this->validated($request));

        return redirect()->route('investor-report.show', $report->id)->with('success', 'Investor report berhasil dibuat.');
    }

    public function show($id)
    {
        $report = InvestorReport::findOrFail($id);

        return view('investor_report.show', $this->service->build($report));
    }

    public function edit($id)
    {
        $report = InvestorReport::findOrFail($id);

        return view('investor_report.form', [
            'report'   => $report,
            'defaults' => [
                'period_start'          => $report->period_start->format('Y-m-d'),
                'period_end'            => $report->period_end->format('Y-m-d'),
                'start_portfolio_value' => $report->start_portfolio_value,
                'end_portfolio_value'   => $report->end_portfolio_value,
                'btc_start_price'       => $report->btc_start_price,
                'btc_end_price'         => $report->btc_end_price,
                'allocation_text'       => $this->service->allocationToText($report->allocation ?? []),
                'portfolio_note'        => $report->portfolio_note,
                'manager_notes'         => $report->manager_notes,
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $report = InvestorReport::findOrFail($id);
        $report->update($this->validated($request, $report));

        return redirect()->route('investor-report.show', $report->id)->with('success', 'Investor report berhasil diperbarui.');
    }

    public function destroy($id)
    {
        InvestorReport::findOrFail($id)->delete();

        return redirect()->route('investor-report.index')->with('success', 'Investor report berhasil dihapus.');
    }

    /** Isian otomatis (JSON) untuk form, dari jurnal trade & harga historis. */
    public function suggest(Request $request)
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
            'except'       => 'nullable|integer',
        ]);

        return response()->json($this->service->suggest(
            Carbon::parse($data['period_start']),
            Carbon::parse($data['period_end']),
            $data['except'] ?? null
        ));
    }

    public function pdf(Request $request, $id)
    {
        $report = InvestorReport::findOrFail($id);
        $data = $this->service->build($report);

        $pdf = Pdf::loadView('investor_report.pdf', $data)
            ->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true);
        $filename = $data['fund_name'] . ' - ' . $report->title() . '.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }

    /**
     * Isian yang dikosongkan dilengkapi otomatis, jadi report bisa dibuat hanya
     * dengan memilih tanggal.
     */
    protected function validated(Request $request, ?InvestorReport $existing = null): array
    {
        $data = $request->validate([
            'period_start'          => 'required|date',
            'period_end'            => 'required|date|after_or_equal:period_start',
            'start_portfolio_value' => 'nullable|numeric|min:0',
            'end_portfolio_value'   => 'nullable|numeric|min:0',
            'btc_start_price'       => 'nullable|numeric|min:0',
            'btc_end_price'         => 'nullable|numeric|min:0',
            'allocation_text'       => 'nullable|string|max:2000',
            'portfolio_note'        => 'nullable|string|max:2000',
            'manager_notes'         => 'nullable|string|max:5000',
        ]);

        $needsSuggestion = collect(['start_portfolio_value', 'end_portfolio_value', 'btc_start_price', 'btc_end_price', 'allocation_text'])
            ->contains(fn ($f) => blank($data[$f] ?? null))
            || !$existing?->usdt_idr_rate
            || $existing->period_end->format('Y-m-d') !== $data['period_end'];

        if ($needsSuggestion) {
            $auto = $this->service->suggest(Carbon::parse($data['period_start']), Carbon::parse($data['period_end']), $existing?->id);

            foreach (['start_portfolio_value', 'end_portfolio_value', 'btc_start_price', 'btc_end_price', 'allocation_text'] as $f) {
                if (blank($data[$f] ?? null)) {
                    $data[$f] = $auto[$f];
                }
            }
            $data['usdt_idr_rate'] = $auto['usdt_idr_rate'] ?? $existing?->usdt_idr_rate;
        }

        foreach (['start_portfolio_value' => $auto['start_message'] ?? null, 'end_portfolio_value' => $auto['end_message'] ?? null] as $f => $msg) {
            if (blank($data[$f])) {
                throw ValidationException::withMessages([$f => $msg ?? 'Nilai portfolio tidak bisa dihitung otomatis, isi manual.']);
            }
        }

        $data['allocation'] = $this->service->parseAllocation($data['allocation_text'] ?? '');
        unset($data['allocation_text']);

        return $data;
    }
}
