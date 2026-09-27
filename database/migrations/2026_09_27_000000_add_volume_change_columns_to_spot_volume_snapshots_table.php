<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahkan kolom perubahan volume ke tabel snapshot.
 *
 * Tabel ini sempat dibuat di produksi sebelum kolom perubahan ada. Karena
 * migration yang sudah jalan tidak akan diulang, kolomnya harus ditambahkan
 * lewat migration tersendiri.
 *
 * Tiap kolom dijaga Schema::hasColumn() supaya migration ini aman dijalankan
 * di database mana pun: menambah yang belum ada, melewati yang sudah.
 */
return new class extends Migration
{
    /** Kolom yang harus ada, beserta acuan posisinya. */
    protected const COLUMNS = [
        'volume_change_percent_1h'  => 'volume_usd_1w',
        'volume_change_percent_4h'  => 'volume_change_percent_1h',
        'volume_change_percent_12h' => 'volume_change_percent_4h',
        'volume_change_percent_24h' => 'volume_change_percent_12h',
        'volume_change_percent_1w'  => 'volume_change_percent_24h',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('spot_volume_snapshots')) {
            return;
        }

        foreach (self::COLUMNS as $kolom => $setelah) {
            if (Schema::hasColumn('spot_volume_snapshots', $kolom)) {
                continue;
            }

            Schema::table('spot_volume_snapshots', function (Blueprint $table) use ($kolom, $setelah) {
                $table->decimal($kolom, 12, 4)->nullable()->after($setelah);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('spot_volume_snapshots')) {
            return;
        }

        foreach (array_keys(self::COLUMNS) as $kolom) {
            if (Schema::hasColumn('spot_volume_snapshots', $kolom)) {
                Schema::table('spot_volume_snapshots', function (Blueprint $table) use ($kolom) {
                    $table->dropColumn($kolom);
                });
            }
        }
    }
};
