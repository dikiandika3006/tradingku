<?php

namespace App\Http\Controllers;

use App\Models\DerivativesAnalysis;
use App\Services\BinanceFuturesService;
use Illuminate\Http\Request;

class DerivativesController extends Controller
{
    protected $binanceService;

    public function __construct(BinanceFuturesService $binanceService)
    {
        $this->binanceService = $binanceService;
    }

    /**
     * Display derivatives analyses list + input form.
     */
    public function index()
    {
        $analyses = DerivativesAnalysis::orderBy('created_at', 'desc')->paginate(10);
        return view('derivatives.index', compact('analyses'));
    }

    /**
     * Store a new derivatives analysis with auto-calculated signal & bottom score.
     */
    public function store(Request $request)
    {
        $request->validate([
            'symbol' => 'required|string|max:20',
            'funding_rate' => 'nullable|numeric',
            'ls_ratio_volume' => 'required|numeric|min:0.0001',
            'ls_ratio_accounts' => 'nullable|numeric|min:0',
            'ls_ratio_top_traders' => 'nullable|numeric|min:0',
            'open_interest_change' => 'required|numeric',
            'volume_change' => 'required|numeric',
            'price_change' => 'required|numeric',
            'liq_long_24h' => 'nullable|numeric|min:0',
            'liq_short_24h' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $data = $request->only([
            'symbol', 'funding_rate', 'ls_ratio_volume', 'ls_ratio_accounts',
            'ls_ratio_top_traders', 'open_interest_change', 'volume_change',
            'price_change', 'liq_long_24h', 'liq_short_24h', 'notes',
        ]);

        $data['symbol'] = strtoupper(trim($data['symbol']));
        $data['liq_long_24h'] = $data['liq_long_24h'] ?? 0;
        $data['liq_short_24h'] = $data['liq_short_24h'] ?? 0;

        // Auto-calculate signal and bottom score
        $data['signal'] = DerivativesAnalysis::calculateSignal($data);
        $data['bottom_score'] = DerivativesAnalysis::calculateBottomScore($data);

        DerivativesAnalysis::create($data);

        return redirect()->route('derivatives.index')->with('success', 'Analisa derivatives berhasil disimpan.');
    }

    /**
     * API: Fetch live derivatives data from Binance Futures for auto-fill.
     */
    public function getLiveData($symbol)
    {
        $data = $this->binanceService->getDerivativesData($symbol);

        if (!$data) {
            return response()->json([
                'success' => false,
                'message' => "Gagal mengambil data derivatives untuk {$symbol}. Pastikan symbol terdaftar di Binance Futures."
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Remove a derivatives analysis.
     */
    public function destroy($id)
    {
        $analysis = DerivativesAnalysis::findOrFail($id);
        $analysis->delete();

        return redirect()->route('derivatives.index')->with('success', 'Analisa derivatives berhasil dihapus.');
    }
}
