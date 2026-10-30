<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan berkala untuk investor (PDF "Investor Report").
 *
 * Yang disimpan hanya angka yang tidak bisa direkonstruksi belakangan: nilai
 * portfolio di awal & akhir periode serta komposisi aset saat laporan dibuat.
 * Modal dan posisi tiap investor tetap dihitung dari investor_transactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('investor_reports')) {
            return;
        }

        Schema::create('investor_reports', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('start_portfolio_value', 20, 2); // Rupiah
            $table->decimal('end_portfolio_value', 20, 2);   // Rupiah
            $table->json('allocation')->nullable();          // [{asset, percent}]
            $table->decimal('usdt_idr_rate', 12, 2)->nullable();  // kurs saat report dibuat
            $table->decimal('btc_start_price', 20, 2)->nullable(); // benchmark, USDT
            $table->decimal('btc_end_price', 20, 2)->nullable();
            $table->text('portfolio_note')->nullable();
            $table->text('manager_notes')->nullable();
            $table->timestamps();

            $table->index('period_end');
        });

        $this->seedAwal();
    }

    /**
     * Report pertama (PDF "Investor Report - 22 Agustus 2026") ikut ditanam agar
     * lokal dan produksi punya titik awal rangkaian report yang sama. Nilai awal
     * periode ini sebelum jurnal trade tercatat, jadi tidak bisa dihitung ulang.
     */
    protected function seedAwal(): void
    {
        if (DB::table('investor_reports')->exists()) {
            return;
        }

        DB::table('investor_reports')->insert([
            'period_start'          => '2026-07-02',
            'period_end'            => '2026-08-22',
            'start_portfolio_value' => 12284410,
            'end_portfolio_value'   => 18567938,
            'allocation'            => json_encode([
                ['asset' => 'USDT',   'percent' => 72.75],
                ['asset' => 'FET',    'percent' => 12.83],
                ['asset' => 'RENDER', 'percent' => 9.40],
                ['asset' => 'STRK',   'percent' => 5.02],
            ]),
            'usdt_idr_rate'         => 17608,
            'btc_start_price'       => 61560,
            'btc_end_price'         => 77074.93,
            'portfolio_note'        => 'Porsi USDT yang besar membuat portfolio saat ini lebih fleksibel menghadapi kondisi market yang masih cukup volatile.',
            'manager_notes'         => "Periode Juli–Agustus merupakan periode market yang cukup menantang. Meskipun kenaikan portfolio belum terlalu besar, **portfolio masih berada dalam posisi profit**.\n\n"
                . "Selama periode berjalan dilakukan penyesuaian posisi dan penambahan modal. Saat ini sebagian besar dana ditempatkan dalam USDT sambil menunggu peluang market yang lebih baik.\n\n"
                . '**Fokus berikutnya:** menjaga modal, mengelola risiko, dan mengambil peluang ketika kondisi market lebih mendukung.',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_reports');
    }
};
