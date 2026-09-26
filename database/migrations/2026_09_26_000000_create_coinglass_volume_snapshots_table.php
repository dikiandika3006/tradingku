<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coinglass_volume_snapshots', function (Blueprint $table) {
            $table->id();

            $table->string('symbol', 20);            // aset dasar, mis. BTC
            $table->string('exchange_name', 60);     // bursa, mis. Binance
            $table->string('pair', 40)->nullable();  // pasangan, mis. BTCUSDT

            $table->decimal('price_usd', 20, 8)->nullable();

            // Volume per timeframe — inti heatmap
            $table->decimal('volume_usd_1h', 24, 2)->nullable();
            $table->decimal('volume_usd_4h', 24, 2)->nullable();
            $table->decimal('volume_usd_12h', 24, 2)->nullable();
            $table->decimal('volume_usd_24h', 24, 2)->nullable();
            $table->decimal('volume_usd_1w', 24, 2)->nullable();

            // Perubahan harga per timeframe, untuk mewarnai heatmap
            $table->decimal('price_change_percent_1h', 12, 4)->nullable();
            $table->decimal('price_change_percent_4h', 12, 4)->nullable();
            $table->decimal('price_change_percent_12h', 12, 4)->nullable();
            $table->decimal('price_change_percent_24h', 12, 4)->nullable();
            $table->decimal('price_change_percent_1w', 12, 4)->nullable();

            $table->decimal('volume_change_percent_24h', 12, 4)->nullable();

            $table->dateTime('captured_at');
            $table->timestamps();

            // Satu bursa hanya boleh punya satu baris per pengambilan
            $table->unique(['symbol', 'exchange_name', 'pair', 'captured_at'], 'cg_vol_unique_capture');
            $table->index(['symbol', 'captured_at'], 'cg_vol_symbol_time');
            $table->index(['exchange_name', 'captured_at'], 'cg_vol_exchange_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coinglass_volume_snapshots');
    }
};
