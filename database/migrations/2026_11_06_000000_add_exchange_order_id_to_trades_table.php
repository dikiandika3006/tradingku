<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor order Tokocrypto pada transaksi jurnal.
 *
 * Dipakai auto-post pembelian dari Tokocrypto: satu order hanya boleh masuk
 * jurnal sekali, termasuk bila sebelumnya sudah dicatat manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('trades', 'exchange_order_id')) {
            Schema::table('trades', function (Blueprint $table) {
                $table->string('exchange_order_id', 64)->nullable()->unique()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('trades', 'exchange_order_id')) {
            Schema::table('trades', function (Blueprint $table) {
                $table->dropUnique(['exchange_order_id']);
                $table->dropColumn('exchange_order_id');
            });
        }
    }
};
