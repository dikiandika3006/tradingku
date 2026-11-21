<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Login pakai username. Email jadi opsional.
 * User lama diberi username dari bagian depan email (superadmin@x.com -> superadmin).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('username', 50)->nullable()->after('name');
            });
        }

        $dipakai = DB::table('users')->whereNotNull('username')->pluck('username')->map(fn ($u) => strtolower($u))->all();
        foreach (DB::table('users')->whereNull('username')->orderBy('id')->get() as $user) {
            $dasar = Str::lower(preg_replace('/[^A-Za-z0-9_.]/', '', Str::before((string) $user->email, '@'))) ?: 'user';
            $calon = $dasar;
            for ($i = 2; in_array($calon, $dipakai, true); $i++) {
                $calon = $dasar . $i;
            }
            $dipakai[] = $calon;
            DB::table('users')->where('id', $user->id)->update(['username' => $calon]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable(false)->change();
            $table->unique('username');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
