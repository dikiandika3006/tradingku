<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master role: menu apa saja yang boleh diakses tiap role.
 *
 * users.role tetap menyimpan slug role, jadi user yang sudah ada tidak perlu
 * diubah. Ketiga role bawaan ditanam dengan akses yang sama seperti sebelum
 * tabel ini ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 50)->unique();
                $table->string('name');
                $table->json('permissions')->nullable(); // daftar kunci App\Support\Menu
                $table->timestamps();
            });
        }

        if (DB::table('roles')->exists()) {
            return;
        }

        $now = now();
        $roles = [
            // Super Admin selalu punya akses penuh; daftar ini hanya informasi.
            'super_admin'   => ['Super Admin', []],
            'investor'      => ['Investor', ['dashboard', 'portfolio']],
            'report_viewer' => ['Viewer Laporan', ['investor_report', 'monthly_report']],
        ];

        foreach ($roles as $slug => [$name, $permissions]) {
            DB::table('roles')->insert([
                'slug'        => $slug,
                'name'        => $name,
                'permissions' => json_encode($permissions),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
