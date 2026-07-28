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
        Schema::create('derivatives_analyses', function (Blueprint $table) {
            $table->id();
            $table->string('symbol'); // e.g. STRK, FET, BTC
            $table->decimal('funding_rate', 10, 6)->nullable(); // e.g. 0.075200
            $table->decimal('ls_ratio_volume', 10, 4); // Long/Short ratio by volume (24h)
            $table->decimal('ls_ratio_accounts', 10, 4)->nullable(); // Long/Short ratio by account count
            $table->decimal('ls_ratio_top_traders', 10, 4)->nullable(); // Long/Short ratio top traders
            $table->decimal('open_interest_change', 8, 2); // OI change in % (e.g. +13.86 or -4.31)
            $table->decimal('volume_change', 10, 2); // Volume change in % (e.g. +668.59)
            $table->decimal('price_change', 8, 2); // Price change in % (e.g. -7.31)
            $table->decimal('liq_long_24h', 16, 2)->default(0); // Liquidation long 24h in USD
            $table->decimal('liq_short_24h', 16, 2)->default(0); // Liquidation short 24h in USD
            $table->string('signal')->default('NEUTRAL'); // STRONG_BEARISH, BEARISH, NEUTRAL, BULLISH, STRONG_BULLISH
            $table->integer('bottom_score')->default(0); // 0-5 checklist score
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('derivatives_analyses');
    }
};
