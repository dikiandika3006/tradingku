<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WatchlistController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ScannerController;
use App\Http\Controllers\DerivativesController;
use App\Http\Controllers\CapitalProjectionController;
use App\Http\Controllers\VolumeHeatmapController;
use App\Http\Controllers\InvestorController;
use App\Http\Controllers\InvestorReportController;
use App\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

// Public Auth Routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Database Sync Routes (Local-only, CORS enabled)
Route::get('/api/database/pending-queries', [SettingController::class, 'getPendingQueries'])->name('api.database.pending-queries');
Route::post('/api/database/clear-pending-queries', [SettingController::class, 'clearPendingQueries'])->name('api.database.clear-pending-queries');
Route::options('/api/database/pending-queries', function() {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With');
});
Route::options('/api/database/clear-pending-queries', function() {
    return response('', 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'POST, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With');
});

// Authenticated Routes. Akses tiap menu diatur di Master Role (middleware menu:<kunci>).
Route::middleware(['auth'])->group(function () {
    // Redirect root ke menu pertama yang boleh diakses
    Route::get('/', function () {
        $home = auth()->user()->homeUrl();
        abort_unless($home, 403, 'Role Anda belum diberi akses ke menu apa pun.');

        return redirect($home);
    });

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard')->middleware('menu:dashboard');

    // Watchlist
    Route::middleware('menu:watchlist')->group(function () {
        Route::get('/watchlist', [WatchlistController::class, 'index'])->name('watchlist.index');
        Route::post('/watchlist', [WatchlistController::class, 'store'])->name('watchlist.store');
        Route::delete('/watchlist/{id}', [WatchlistController::class, 'destroy'])->name('watchlist.destroy');
        Route::get('/api/watchlist-metrics/{symbol}', [WatchlistController::class, 'getMetrics'])->name('api.watchlist-metrics');
    });

    // Jurnal Trading
    Route::middleware('menu:trade')->group(function () {
        Route::get('/trade', [TradeController::class, 'index'])->name('trade.index');
        Route::post('/trade', [TradeController::class, 'store'])->name('trade.store');
        Route::put('/trade/{id}', [TradeController::class, 'update'])->name('trade.update');
        Route::delete('/trade/{id}', [TradeController::class, 'destroy'])->name('trade.destroy');
        Route::get('/api/trade-live-stats/{symbol}', [TradeController::class, 'getLiveStats'])->name('api.trade-live-stats');
        Route::get('/api/trade-holding/{symbol}', [TradeController::class, 'getHolding'])->name('api.trade-holding');
    });

    // Scanner
    Route::middleware('menu:scanner')->group(function () {
        Route::get('/scanner', [ScannerController::class, 'index'])->name('scanner.index');
        Route::get('/api/scanner/results', [ScannerController::class, 'getResults'])->name('api.scanner.results');
        Route::get('/api/scanner/all', [ScannerController::class, 'getAllResults'])->name('api.scanner.all');
        Route::post('/api/scanner/trigger', [ScannerController::class, 'startScan'])->name('api.scanner.trigger');
        Route::get('/api/scanner/analyse/{symbol}', [ScannerController::class, 'analyseSymbol'])->name('api.scanner.analyse');
    });

    // Derivatives Analysis
    Route::middleware('menu:derivatives')->group(function () {
        Route::get('/derivatives', [DerivativesController::class, 'index'])->name('derivatives.index');
        Route::post('/derivatives', [DerivativesController::class, 'store'])->name('derivatives.store');
        Route::delete('/derivatives/{id}', [DerivativesController::class, 'destroy'])->name('derivatives.destroy');
        Route::get('/api/derivatives/live/{symbol}', [DerivativesController::class, 'getLiveData'])->name('api.derivatives.live');
    });

    // Volume spot terakumulasi lintas bursa
    Route::get('/derivatives/volume-heatmap', [VolumeHeatmapController::class, 'index'])->name('derivatives.volume-heatmap')->middleware('menu:volume_heatmap');

    Route::get('/portfolio', [PortfolioController::class, 'index'])->name('portfolio.index')->middleware('menu:portfolio');
    Route::post('/portfolio/sync-tokocrypto', [PortfolioController::class, 'syncTokocrypto'])->name('portfolio.sync-tokocrypto')->middleware('menu:trade');
    Route::get('/pnl',[PortfolioController::class, 'pnl'])->name('portfolio.pnl')->middleware('menu:pnl');
    Route::get('/portfolio/monthly-report', [PortfolioController::class, 'monthlyReport'])->name('portfolio.monthly-report')->middleware('menu:monthly_report');
    Route::get('/open-orders', [PortfolioController::class, 'openOrders'])->name('portfolio.open-orders')->middleware('menu:open_orders');
    Route::get('/portfolio/dca-calculator', [PortfolioController::class, 'dcaCalculator'])->name('portfolio.dca-calculator')->middleware('menu:dca_calculator');

    // Proyeksi pertumbuhan modal
    Route::middleware('menu:capital_projection')->group(function () {
        Route::get('/portfolio/capital-projection', [CapitalProjectionController::class, 'index'])->name('portfolio.capital-projection');
        Route::post('/portfolio/capital-projection', [CapitalProjectionController::class, 'update'])->name('portfolio.capital-projection.update');
        Route::post('/portfolio/capital-projection/rows', [CapitalProjectionController::class, 'updateRows'])->name('portfolio.capital-projection.rows');
        Route::post('/portfolio/capital-projection/reset', [CapitalProjectionController::class, 'resetRows'])->name('portfolio.capital-projection.reset');
    });

    // Master investor & riwayat setoran dana
    Route::middleware('menu:investor')->group(function () {
        Route::get('/investor', [InvestorController::class, 'index'])->name('investor.index');
        Route::post('/investor', [InvestorController::class, 'storeInvestor'])->name('investor.store');
        Route::put('/investor/{id}', [InvestorController::class, 'updateInvestor'])->name('investor.update');
        Route::delete('/investor/{id}', [InvestorController::class, 'destroyInvestor'])->name('investor.destroy');
        Route::post('/investor-transaction', [InvestorController::class, 'storeTransaction'])->name('investor.transaction.store');
        Route::put('/investor-transaction/{id}', [InvestorController::class, 'updateTransaction'])->name('investor.transaction.update');
        Route::delete('/investor-transaction/{id}', [InvestorController::class, 'destroyTransaction'])->name('investor.transaction.destroy');
    });

    // Investor report: lihat & unduh PDF
    Route::middleware('menu:investor_report')->group(function () {
        Route::get('/investor-report', [InvestorReportController::class, 'index'])->name('investor-report.index');
        Route::get('/investor-report/{id}', [InvestorReportController::class, 'show'])->name('investor-report.show')->whereNumber('id');
        Route::get('/investor-report/{id}/pdf', [InvestorReportController::class, 'pdf'])->name('investor-report.pdf')->whereNumber('id');
    });

    // Investor report: buat, ubah, hapus
    Route::middleware('menu:investor_report_manage')->group(function () {
        Route::get('/investor-report/create', [InvestorReportController::class, 'create'])->name('investor-report.create');
        Route::post('/investor-report', [InvestorReportController::class, 'store'])->name('investor-report.store');
        Route::get('/investor-report/suggest', [InvestorReportController::class, 'suggest'])->name('investor-report.suggest');
        Route::get('/investor-report/{id}/edit', [InvestorReportController::class, 'edit'])->name('investor-report.edit');
        Route::put('/investor-report/{id}', [InvestorReportController::class, 'update'])->name('investor-report.update');
        Route::delete('/investor-report/{id}', [InvestorReportController::class, 'destroy'])->name('investor-report.destroy');
    });

    // User Management
    Route::middleware('menu:user')->group(function () {
        Route::get('/user', [UserController::class, 'index'])->name('user.index');
        Route::post('/user', [UserController::class, 'store'])->name('user.store');
        Route::put('/user/{id}', [UserController::class, 'update'])->name('user.update');
        Route::delete('/user/{id}', [UserController::class, 'destroy'])->name('user.destroy');
    });

    // Master Role: menu yang boleh diakses tiap role
    Route::middleware('menu:role')->group(function () {
        Route::get('/role', [RoleController::class, 'index'])->name('role.index');
        Route::post('/role', [RoleController::class, 'store'])->name('role.store');
        Route::put('/role/{id}', [RoleController::class, 'update'])->name('role.update');
        Route::delete('/role/{id}', [RoleController::class, 'destroy'])->name('role.destroy');
    });

    // Settings, Telegram & sinkron database
    Route::middleware('menu:setting')->group(function () {
        Route::get('/setting', [SettingController::class, 'index'])->name('setting.index');
        Route::post('/setting', [SettingController::class, 'update'])->name('setting.update');
        Route::get('/setting/database/export', [SettingController::class, 'exportDatabase'])->name('setting.database.export');
        Route::post('/setting/database/import', [SettingController::class, 'importDatabase'])->name('setting.database.import');

        // Telegram Settings Routes
        Route::post('/setting/telegram', [SettingController::class, 'updateTelegram'])->name('setting.telegram.update');
        Route::post('/setting/telegram/recipient', [SettingController::class, 'storeTelegramRecipient'])->name('setting.telegram.recipient.store');
        Route::post('/setting/telegram/recipient/{id}/toggle', [SettingController::class, 'toggleTelegramRecipient'])->name('setting.telegram.recipient.toggle');
        Route::post('/setting/telegram/recipient/{id}/test', [SettingController::class, 'testTelegramRecipient'])->name('setting.telegram.recipient.test');
        Route::delete('/setting/telegram/recipient/{id}', [SettingController::class, 'destroyTelegramRecipient'])->name('setting.telegram.recipient.destroy');
        Route::post('/setting/database/migrate', [SettingController::class, 'runMigrations'])->name('setting.database.migrate');
        Route::post('/setting/telegram/check', [SettingController::class, 'triggerPriceCheck'])->name('setting.telegram.check');

        // Database Sync Routes (Browser-Bridge)
        Route::post('/api/database/apply-queries', [SettingController::class, 'applyPendingQueries'])->name('api.database.apply-queries');
        Route::get('/setting/coinalyze-debug', [SettingController::class, 'debugCoinalyze']);
    });
});
