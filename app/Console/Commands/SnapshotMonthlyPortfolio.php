<?php

namespace App\Console\Commands;

use App\Models\MonthlyPortfolioSnapshot;
use App\Services\MonthlyReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Monthly Portfolio Snapshot Engine - Cut-Off Runner.
 *
 * Mengunci (finalize) valuasi akhir bulan agar laporan bulan lampau menjadi
 * immutable dan tidak ikut berubah saat harga koin bergerak.
 */
class SnapshotMonthlyPortfolio extends Command
{
    protected $signature = 'portfolio:snapshot
                            {--month= : Periode yang di-snapshot (format YYYY-MM). Default: bulan berjalan}
                            {--finalize : Kunci permanen snapshot (is_finalized = true)}
                            {--force : Timpa snapshot yang sudah difinalisasi}
                            {--backfill : Kunci semua bulan lampau yang belum punya snapshot, lalu refresh bulan berjalan}';

    protected $description = 'Cut-off & kunci valuasi portofolio akhir bulan (Monthly Portfolio Snapshot Engine)';

    protected $reportService;

    public function __construct(MonthlyReportService $reportService)
    {
        parent::__construct();
        $this->reportService = $reportService;
    }

    public function handle(): int
    {
        $currentYm = date('Y-m');

        $this->info('Menghitung data portofolio...');
        $data = $this->reportService->build();

        $reports = [];
        foreach ($data['reports'] as $row) {
            $reports[$row['year_month']] = $row;
        }

        if (empty($reports)) {
            $this->warn('Tidak ada data bulanan untuk di-snapshot.');
            return self::SUCCESS;
        }

        if ($this->option('backfill')) {
            return $this->runBackfill($reports, $currentYm);
        }

        $ym = $this->option('month') ?: $currentYm;

        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
            $this->error("Format --month tidak valid: {$ym}. Gunakan YYYY-MM (contoh: 2026-07).");
            return self::FAILURE;
        }

        if (!isset($reports[$ym])) {
            $this->error("Periode {$ym} tidak ditemukan dalam data laporan.");
            return self::FAILURE;
        }

        $finalize = (bool)$this->option('finalize') || $ym < $currentYm;

        return $this->persist($reports[$ym], $ym, $currentYm, $finalize)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * Kunci semua bulan lampau yang belum terkunci + refresh snapshot bulan berjalan.
     */
    protected function runBackfill(array $reports, string $currentYm): int
    {
        $locked = 0;

        foreach ($reports as $ym => $row) {
            if ($ym >= $currentYm) {
                continue;
            }

            if ($row['is_finalized'] && !$this->option('force')) {
                continue;
            }

            if ($this->persist($row, $ym, $currentYm, true)) {
                $locked++;
            }
        }

        // Bulan berjalan disimpan sebagai snapshot LIVE (belum dikunci)
        if (isset($reports[$currentYm])) {
            $this->persist($reports[$currentYm], $currentYm, $currentYm, false);
        }

        $this->info("Backfill selesai. {$locked} bulan lampau dikunci, bulan berjalan ({$currentYm}) di-refresh sebagai LIVE.");

        return self::SUCCESS;
    }

    /**
     * Simpan satu periode ke tabel snapshot.
     */
    protected function persist(array $row, string $ym, string $currentYm, bool $finalize): bool
    {
        $existing = MonthlyPortfolioSnapshot::where('year_month', $ym)->first();

        if ($existing && $existing->is_finalized && !$this->option('force')) {
            $this->warn("Periode {$ym} sudah dikunci pada {$existing->cut_off_date}. Lewati (pakai --force untuk menimpa).");
            return false;
        }

        // Bulan berjalan di-cut-off saat perintah dijalankan; bulan lampau di akhir bulannya
        $cutOffDate = $ym === $currentYm
            ? now()
            : Carbon::createFromFormat('Y-m-d', $ym . '-01')->endOfMonth();

        MonthlyPortfolioSnapshot::updateOrCreate(
            ['year_month' => $ym],
            [
                'cut_off_date'          => $cutOffDate,
                'ending_valuation_usdt' => $row['portfolio_valuation_usdt'],
                'ending_valuation_idr'  => $row['portfolio_valuation_idr'],
                'realized_pnl_usdt'     => $row['realized_pnl_usdt'],
                'realized_pnl_idr'      => $row['realized_pnl_idr'],
                'floating_pnl_usdt'     => $row['floating_pnl_usdt'],
                'floating_pnl_idr'      => $row['floating_pnl_idr'],
                'true_net_pnl_usdt'     => $row['true_net_pnl_usdt'],
                'true_net_pnl_idr'      => $row['true_net_pnl_idr'],
                'nominal_growth_usdt'   => $row['nominal_growth_usdt'],
                'nominal_growth_idr'    => $row['nominal_growth_idr'],
                'mom_growth_percent'    => $row['mom_growth_percent'],
                'trades_count'          => $row['trades_count'],
                'win_rate'              => $row['win_rate'],
                'health_badge'          => $row['health_badge'],
                'top_asset'             => $row['top_asset'],
                'is_finalized'          => $finalize,
            ]
        );

        $status = $finalize ? 'DIKUNCI' : 'LIVE';
        $this->line(sprintf(
            '  [%s] %s | Saldo Akhir: $%s (Rp %s) | True Net PNL: %s$%s | MoM: %s%%',
            $status,
            $ym,
            number_format($row['portfolio_valuation_usdt'], 2),
            number_format($row['portfolio_valuation_idr'], 0, ',', '.'),
            $row['true_net_pnl_usdt'] >= 0 ? '+' : '',
            number_format($row['true_net_pnl_usdt'], 2),
            number_format($row['mom_growth_percent'], 2)
        ));

        if ($finalize) {
            Log::info("Monthly portfolio snapshot finalized for {$ym}", [
                'ending_valuation_usdt' => $row['portfolio_valuation_usdt'],
                'true_net_pnl_usdt'     => $row['true_net_pnl_usdt'],
                'cut_off_date'          => $cutOffDate->toDateTimeString(),
            ]);
        }

        return true;
    }
}
