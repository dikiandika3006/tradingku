<?php

namespace App\Http\Controllers;

use App\Models\Investor;
use App\Models\InvestorTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvestorController extends Controller
{
    /**
     * Master investor, kepemilikan saat ini, rekap bulanan, dan riwayat transaksi.
     */
    public function index(Request $request)
    {
        $investors = Investor::orderBy('code')->get();
        $transactions = InvestorTransaction::with('investor')
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        // Telusuri riwayat secara kronologis untuk mendapat saldo berjalan tiap
        // investor dan porsi kepemilikannya tepat setelah setiap transaksi.
        $saldo = $investors->mapWithKeys(fn ($i) => [$i->id => 0.0])->all();
        $history = [];
        foreach ($transactions as $tx) {
            $saldo[$tx->investor_id] = ($saldo[$tx->investor_id] ?? 0) + $tx->signedAmount();
            $totalDana = array_sum($saldo);

            $history[] = [
                'tx'          => $tx,
                'saldo'       => $saldo[$tx->investor_id],
                'total_dana'  => $totalDana,
                'kepemilikan' => $totalDana > 0 ? $saldo[$tx->investor_id] / $totalDana * 100 : 0,
            ];
        }

        $totalDana = array_sum($saldo);

        $summary = $investors->map(function ($inv) use ($transactions, $saldo, $totalDana) {
            $milik = $transactions->where('investor_id', $inv->id);

            return [
                'investor'    => $inv,
                'modal_awal'  => $milik->where('type', 'initial_capital')->sum('amount'),
                'top_up'      => $milik->where('type', 'top_up')->sum('amount'),
                'penarikan'   => $milik->where('type', 'withdrawal')->sum('amount'),
                'total'       => $saldo[$inv->id] ?? 0,
                'kepemilikan' => $totalDana > 0 ? ($saldo[$inv->id] ?? 0) / $totalDana * 100 : 0,
                'jumlah_tx'   => $milik->count(),
                'terakhir'    => $milik->last()?->date,
            ];
        });

        $rekapBulanan = $this->rekapBulanan($transactions, $investors);

        $bulanIni = now()->format('Y-m');
        $stats = [
            'total_dana'      => $totalDana,
            'investor_aktif'  => $investors->where('status', 'aktif')->count(),
            'setoran_bulan'   => $transactions
                ->filter(fn ($t) => $t->date->format('Y-m') === $bulanIni)
                ->sum(fn ($t) => $t->signedAmount()),
            'jumlah_tx'       => $transactions->count(),
        ];

        // Filter hanya memengaruhi tampilan; saldo berjalan tetap dihitung dari seluruh riwayat.
        $filterInvestor = $request->integer('investor') ?: null;
        $historyTampil = collect($history)
            ->when($filterInvestor, fn ($c) => $c->filter(fn ($h) => $h['tx']->investor_id === $filterInvestor))
            ->reverse()
            ->values();

        return view('investor.index', [
            'investors'      => $investors,
            'summary'        => $summary,
            'rekapBulanan'   => $rekapBulanan,
            'history'        => $historyTampil,
            'stats'          => $stats,
            'filterInvestor' => $filterInvestor,
            'nextCode'       => Investor::nextCode(),
            'types'          => InvestorTransaction::TYPES,
        ]);
    }

    public function storeInvestor(Request $request)
    {
        $data = $this->validateInvestor($request);
        $data['code'] = Investor::nextCode();

        Investor::create($data);

        return redirect()->route('investor.index')->with('success', "Investor {$data['name']} ({$data['code']}) berhasil ditambahkan.");
    }

    public function updateInvestor(Request $request, $id)
    {
        $investor = Investor::findOrFail($id);
        $investor->update($this->validateInvestor($request));

        return redirect()->route('investor.index')->with('success', "Data investor {$investor->name} berhasil diperbarui.");
    }

    public function destroyInvestor($id)
    {
        $investor = Investor::findOrFail($id);

        // Riwayat dana tidak boleh hilang diam-diam; investor yang sudah keluar cukup dinonaktifkan.
        if ($investor->transactions()->exists()) {
            return redirect()->route('investor.index')->with('error', "Investor {$investor->name} masih punya riwayat transaksi. Ubah statusnya menjadi nonaktif, atau hapus transaksinya terlebih dahulu.");
        }

        $investor->delete();

        return redirect()->route('investor.index')->with('success', "Investor {$investor->name} berhasil dihapus.");
    }

    public function storeTransaction(Request $request)
    {
        $data = $this->validateTransaction($request);

        InvestorTransaction::create($data);

        return redirect()->route('investor.index')->with('success', 'Transaksi investor berhasil dicatat.');
    }

    public function updateTransaction(Request $request, $id)
    {
        $tx = InvestorTransaction::findOrFail($id);
        $tx->update($this->validateTransaction($request, $tx));

        return redirect()->route('investor.index')->with('success', 'Transaksi investor berhasil diperbarui.');
    }

    public function destroyTransaction($id)
    {
        InvestorTransaction::findOrFail($id)->delete();

        return redirect()->route('investor.index')->with('success', 'Transaksi investor berhasil dihapus.');
    }

    protected function validateInvestor(Request $request): array
    {
        return $request->validate([
            'name'      => 'required|string|max:255',
            'join_date' => 'required|date',
            'status'    => 'required|in:aktif,nonaktif',
            'notes'     => 'nullable|string|max:1000',
        ]);
    }

    protected function validateTransaction(Request $request, ?InvestorTransaction $existing = null): array
    {
        $data = $request->validate([
            'investor_id' => 'required|exists:investors,id',
            'date'        => 'required|date',
            'type'        => ['required', Rule::in(array_keys(InvestorTransaction::TYPES))],
            'amount'      => 'required|numeric|gt:0',
            'notes'       => 'nullable|string|max:500',
        ]);

        if ($data['type'] === 'withdrawal') {
            $saldo = InvestorTransaction::where('investor_id', $data['investor_id'])
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->get()
                ->sum(fn ($t) => $t->signedAmount());

            if ($data['amount'] > $saldo) {
                throw ValidationException::withMessages([
                    'amount' => 'Penarikan melebihi dana investor (Rp ' . number_format($saldo, 0, ',', '.') . ').',
                ]);
            }
        }

        return $data;
    }

    /**
     * Setoran bersih per investor per bulan, beserta total dana kumulatif di akhir bulan.
     */
    protected function rekapBulanan($transactions, $investors): array
    {
        if ($transactions->isEmpty()) {
            return [];
        }

        $mulai = $transactions->first()->date->copy()->startOfMonth();
        $akhir = $transactions->last()->date->copy()->startOfMonth();
        $perBulan = $transactions->groupBy(fn ($t) => $t->date->format('Y-m'));

        $rows = [];
        $kumulatif = 0.0;
        for ($b = $mulai->copy(); $b <= $akhir; $b->addMonth()) {
            $ym = $b->format('Y-m');
            $isi = $perBulan->get($ym, collect());

            $perInvestor = [];
            foreach ($investors as $inv) {
                $perInvestor[$inv->id] = $isi->where('investor_id', $inv->id)->sum(fn ($t) => $t->signedAmount());
            }

            $totalBulan = array_sum($perInvestor);
            $kumulatif += $totalBulan;

            $rows[] = [
                'label'        => Carbon::parse($ym . '-01')->locale('id')->translatedFormat('M Y'),
                'per_investor' => $perInvestor,
                'total'        => $totalBulan,
                'kumulatif'    => $kumulatif,
            ];
        }

        return $rows;
    }
}
