<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahkan penanda kapan sebuah aset terakhir terlihat di portofolio.
 *
 * Tanpa ini, patokan harga aset yang sudah dijual tertinggal di harga lama.
 * Begitu aset itu dibeli lagi, pemeriksaan pertama membandingkan harga sekarang
 * dengan patokan berbulan lalu dan mengirim alert "lonjakan" yang tidak pernah
 * terjadi.
 *
 * Kolomnya dijaga hasColumn() supaya aman dijalankan berulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('telegram_price_alerts')) {
            return;
        }

        if (Schema::hasColumn('telegram_price_alerts', 'last_seen_at')) {
            return;
        }

        Schema::table('telegram_price_alerts', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('last_alert_time');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('telegram_price_alerts')
            && Schema::hasColumn('telegram_price_alerts', 'last_seen_at')) {
            Schema::table('telegram_price_alerts', function (Blueprint $table) {
                $table->dropColumn('last_seen_at');
            });
        }
    }
};
