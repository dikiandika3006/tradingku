<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spot_volume_snapshots', function (Blueprint $table) {
            $table->id();

            $table->string('symbol', 20);            // aset dasar, mis. BTC
            $table->string('exchange_name', 60);     // bursa, mis. Binance
            $table->string('pair', 40)->nullable();  // pasangan di bursa itu

            $table->decimal('price_usd', 20, 8)->nullable();

            // Volume USD terakumulasi per jendela waktu
            $table->decimal('volume_usd_1h', 24, 2)->default(0);
            $table->decimal('volume_usd_4h', 24, 2)->default(0);
            $table->decimal('volume_usd_12h', 24, 2)->default(0);
            $table->decimal('volume_usd_24h', 24, 2)->default(0);
            $table->decimal('volume_usd_1w', 24, 2)->default(0);

            // Perubahan volume terhadap periode setara sebelumnya.
            // Null bila pembandingnya belum tersedia — dibedakan dari 0% yang
            // berarti benar-benar tidak berubah.
            $table->decimal('volume_change_percent_1h', 12, 4)->nullable();
            $table->decimal('volume_change_percent_4h', 12, 4)->nullable();
            $table->decimal('volume_change_percent_12h', 12, 4)->nullable();
            $table->decimal('volume_change_percent_24h', 12, 4)->nullable();
            $table->decimal('volume_change_percent_1w', 12, 4)->nullable();

            $table->dateTime('captured_at');
            // Tanggal dipisah agar kunci unik bisa menjamin satu rekaman per hari
            $table->date('captured_date');

            $table->timestamps();

            // Satu bursa hanya boleh punya satu rekaman per hari
            $table->unique(['symbol', 'exchange_name', 'captured_date'], 'spot_vol_harian_unik');
            $table->index(['symbol', 'captured_date'], 'spot_vol_symbol_tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spot_volume_snapshots');
    }
};
