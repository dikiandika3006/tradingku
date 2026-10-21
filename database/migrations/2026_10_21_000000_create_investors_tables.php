<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master investor dan riwayat setoran/penarikan dana.
 *
 * Total dana dan persentase kepemilikan tidak disimpan, melainkan selalu
 * dihitung dari riwayat transaksi supaya tidak pernah selisih dengan historinya.
 *
 * Data awal (sheet "Pelagic Capital") ikut ditanam di sini agar lokal dan
 * produksi mendapat isi yang sama cukup dengan menjalankan migrasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('investors')) {
            Schema::create('investors', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();      // contoh: INV-001
                $table->string('name');
                $table->date('join_date');
                $table->string('status', 20)->default('aktif'); // aktif | nonaktif
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('investor_transactions')) {
            Schema::create('investor_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('investor_id')->constrained('investors')->cascadeOnDelete();
                $table->date('date');
                // initial_capital | top_up | withdrawal — nominal selalu positif,
                // arahnya ditentukan oleh jenis.
                $table->string('type', 20);
                $table->decimal('amount', 20, 2);
                $table->string('notes', 500)->nullable();
                $table->timestamps();

                $table->index(['investor_id', 'date']);
            });
        }

        $this->seedAwal();
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_transactions');
        Schema::dropIfExists('investors');
    }

    protected function seedAwal(): void
    {
        if (DB::table('investors')->exists()) {
            return;
        }

        $now = now();

        $investors = [
            'Hasbiar'    => 'INV-001',
            'Diki'       => 'INV-002',
            'Fiqih'      => 'INV-003',
            'Fins Mochi' => 'INV-004',
        ];

        $ids = [];
        foreach ($investors as $name => $code) {
            $ids[$name] = DB::table('investors')->insertGetId([
                'code'       => $code,
                'name'       => $name,
                'join_date'  => '2026-06-19',
                'status'     => 'aktif',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $transaksi = [
            ['2026-06-18', 'Fiqih',      'initial_capital', 3000000],
            ['2026-06-19', 'Fins Mochi', 'initial_capital', 3000000],
            ['2026-06-19', 'Diki',       'initial_capital', 3000000],
            ['2026-06-19', 'Hasbiar',    'initial_capital', 2161000],
            ['2026-06-20', 'Hasbiar',    'initial_capital', 839000],
            ['2026-07-10', 'Fiqih',      'top_up', 500000],
            ['2026-07-10', 'Fins Mochi', 'top_up', 500000],
            ['2026-07-10', 'Hasbiar',    'top_up', 1000000],
            ['2026-07-10', 'Diki',       'top_up', 1000000],
            ['2026-07-28', 'Hasbiar',    'top_up', 500000],
            ['2026-08-01', 'Fiqih',      'top_up', 500000],
            ['2026-08-01', 'Fins Mochi', 'top_up', 500000],
            ['2026-08-20', 'Hasbiar',    'top_up', 500000],
            ['2026-08-20', 'Diki',       'top_up', 1000000],
            ['2026-09-01', 'Hasbiar',    'top_up', 1000000],
            ['2026-09-01', 'Fins Mochi', 'top_up', 1500000],
            ['2026-09-10', 'Fiqih',      'top_up', 500000],
            ['2026-09-21', 'Diki',       'top_up', 1000000],
            ['2026-10-20', 'Fins Mochi', 'top_up', 1000000],
            ['2026-10-20', 'Hasbiar',    'top_up', 1500000],
            ['2026-10-20', 'Diki',       'top_up', 1500000],
            ['2026-10-20', 'Fiqih',      'top_up', 500000],
        ];

        DB::table('investor_transactions')->insert(array_map(fn ($t) => [
            'investor_id' => $ids[$t[1]],
            'date'        => $t[0],
            'type'        => $t[2],
            'amount'      => $t[3],
            'created_at'  => $now,
            'updated_at'  => $now,
        ], $transaksi));
    }
};
