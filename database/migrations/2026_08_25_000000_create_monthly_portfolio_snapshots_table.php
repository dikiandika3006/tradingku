<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('monthly_portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('year_month', 7)->unique(); // e.g. "2026-01"
            $table->dateTime('cut_off_date');
            $table->decimal('ending_valuation_usdt', 16, 4)->default(0);
            $table->decimal('ending_valuation_idr', 16, 2)->default(0);
            $table->decimal('realized_pnl_usdt', 16, 4)->default(0);
            $table->decimal('realized_pnl_idr', 16, 2)->default(0);
            $table->decimal('floating_pnl_usdt', 16, 4)->default(0);
            $table->decimal('floating_pnl_idr', 16, 2)->default(0);
            $table->decimal('true_net_pnl_usdt', 16, 4)->default(0);
            $table->decimal('true_net_pnl_idr', 16, 2)->default(0);
            $table->decimal('nominal_growth_usdt', 16, 4)->default(0);
            $table->decimal('nominal_growth_idr', 16, 2)->default(0);
            $table->decimal('mom_growth_percent', 8, 2)->default(0);
            $table->integer('trades_count')->default(0);
            $table->decimal('win_rate', 5, 2)->default(0);
            $table->string('health_badge')->default('🟢 Sehat (Profit Clean)');
            $table->string('top_asset')->nullable();
            $table->boolean('is_finalized')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_portfolio_snapshots');
    }
};
