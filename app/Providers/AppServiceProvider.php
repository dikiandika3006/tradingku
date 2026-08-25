<?php

namespace App\Providers;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Query yang dieksekusi di dalam transaksi, ditahan sampai transaksinya commit.
     *
     * @var array<int, string>
     */
    protected static array $transactionBuffer = [];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.env') !== 'local') {
            return;
        }

        DB::listen(function ($query) {
            $sql = $this->buildLoggableSql($query);

            if ($sql === null) {
                return;
            }

            // Query di dalam transaksi ditahan dulu: baru dicatat kalau commit,
            // dibuang kalau rollback. Tanpa ini, skrip uji yang sengaja di-rollback
            // tetap masuk antrean sinkronisasi dan bisa terkirim ke produksi.
            if (DB::transactionLevel() > 0) {
                static::$transactionBuffer[] = $sql;
                return;
            }

            $this->appendPendingQueries([$sql]);
        });

        Event::listen(TransactionCommitted::class, function () {
            // Masih di dalam transaksi luar — tunggu sampai yang terluar commit
            if (DB::transactionLevel() > 0 || empty(static::$transactionBuffer)) {
                return;
            }

            $this->appendPendingQueries(static::$transactionBuffer);
            static::$transactionBuffer = [];
        });

        Event::listen(TransactionRolledBack::class, function () {
            // Sengaja konservatif: rollback apa pun (termasuk ke savepoint) membuang
            // seluruh buffer. Lebih baik kehilangan satu entri antrean daripada
            // menyinkronkan query yang sebenarnya sudah dibatalkan.
            static::$transactionBuffer = [];
        });
    }

    /**
     * Ubah query event jadi SQL mentah yang layak dicatat, atau null kalau dilewati.
     */
    protected function buildLoggableSql($query): ?string
    {
        $sql = $query->sql;

        // Hanya rekam perubahan data
        if (!preg_match('/^\s*(insert|update|delete)/i', $sql)) {
            return null;
        }

        // Hanya tabel inti yang ikut disinkronkan
        if (!preg_match('/(trades|watchlists|settings|users)/i', $sql)) {
            return null;
        }

        // Abaikan tabel infrastruktur
        foreach (['sessions', 'cache', 'jobs', 'migrations'] as $skip) {
            if (str_contains($sql, $skip)) {
                return null;
            }
        }

        // Token "remember me" diregenerasi tiap kali login — itu derau sesi, bukan
        // perubahan data. Isinya kredensial, jadi jangan sampai menumpuk di file
        // antrean atau tampil di layar saat pratinjau sinkronisasi.
        if (stripos($sql, 'remember_token') !== false) {
            return null;
        }

        // Susun ulang SQL lengkap dengan bindings-nya
        foreach ($query->bindings as $binding) {
            if ($binding instanceof \DateTime) {
                $val = "'" . $binding->format('Y-m-d H:i:s') . "'";
            } elseif (is_numeric($binding)) {
                $val = $binding;
            } elseif (is_null($binding)) {
                $val = 'NULL';
            } else {
                $val = "'" . addslashes($binding) . "'";
            }

            $sql = preg_replace('/\?/', $val, $sql, 1);
        }

        return $sql;
    }

    /**
     * Tambahkan satu atau lebih SQL ke antrean sinkronisasi.
     *
     * @param array<int, string> $statements
     */
    protected function appendPendingQueries(array $statements): void
    {
        if (empty($statements)) {
            return;
        }

        $logPath = storage_path('app/pending_queries.json');

        $queries = [];
        if (file_exists($logPath)) {
            $queries = json_decode(file_get_contents($logPath), true) ?? [];
        }

        foreach ($statements as $sql) {
            $queries[] = [
                'id' => uniqid(),
                'sql' => $sql,
                'timestamp' => time(),
            ];
        }

        file_put_contents($logPath, json_encode($queries, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
