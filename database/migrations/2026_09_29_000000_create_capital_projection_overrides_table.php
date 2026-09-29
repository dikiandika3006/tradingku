<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nilai proyeksi yang ditimpa manual per bulan.
 *
 * Kolomnya sengaja nullable: null berarti "pakai hasil hitungan otomatis",
 * bukan nol. Itu membedakan bulan yang sengaja disetel nol dari bulan yang
 * memang belum pernah disentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('capital_projection_overrides')) {
            return;
        }

        Schema::create('capital_projection_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('year_month', 7)->unique();   // contoh: 2026-09

            $table->decimal('top_up', 20, 2)->nullable();
            $table->decimal('return_idr', 20, 2)->nullable();
            $table->decimal('alokasi_btc', 20, 2)->nullable();
            $table->decimal('alokasi_alt', 20, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capital_projection_overrides');
    }
};
