<?php

namespace App\Support;

/**
 * Daftar hak akses yang bisa diberikan ke sebuah role. Urutan di sini juga
 * urutan sidebar, dan menu pertama yang boleh diakses menjadi halaman awal
 * user setelah login.
 */
class Menu
{
    /** kunci => [label, grup, nama route halaman utama (null = bukan menu)] */
    public const ITEMS = [
        'dashboard'              => ['Dashboard', 'Trading', 'dashboard'],
        'watchlist'              => ['Watchlist', 'Trading', 'watchlist.index'],
        'trade'                  => ['Jurnal Transaksi', 'Trading', 'trade.index'],
        'scanner'                => ['Scanner Altcoin', 'Trading', 'scanner.index'],
        'derivatives'            => ['Analisa Derivatives', 'Trading', 'derivatives.index'],
        'volume_heatmap'         => ['Heatmap Volume', 'Trading', 'derivatives.volume-heatmap'],
        'portfolio'              => ['Portofolio', 'Portofolio', 'portfolio.index'],
        'pnl'                    => ['Realisasi PNL', 'Portofolio', 'portfolio.pnl'],
        'monthly_report'         => ['Laporan Bulanan', 'Portofolio', 'portfolio.monthly-report'],
        'capital_projection'     => ['Proyeksi Modal', 'Portofolio', 'portfolio.capital-projection'],
        'dca_calculator'         => ['Kalkulator DCA & TP', 'Portofolio', 'portfolio.dca-calculator'],
        'open_orders'            => ['Antrean Pesanan', 'Portofolio', 'portfolio.open-orders'],
        'investor'               => ['Master Investor', 'Investor', 'investor.index'],
        'investor_report'        => ['Investor Report (lihat & PDF)', 'Investor', 'investor-report.index'],
        'investor_report_manage' => ['Investor Report (buat, ubah, hapus)', 'Investor', null],
        'user'                   => ['Daftar User', 'Administrasi', 'user.index'],
        'role'                   => ['Master Role', 'Administrasi', 'role.index'],
        'setting'                => ['API Settings', 'Administrasi', 'setting.index'],
    ];

    public static function keys(): array
    {
        return array_keys(self::ITEMS);
    }

    public static function label(string $key): string
    {
        return self::ITEMS[$key][0] ?? $key;
    }

    /** @return array<string, array<string, string>> grup => [kunci => label] */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::ITEMS as $key => [$label, $group]) {
            $groups[$group][$key] = $label;
        }

        return $groups;
    }

    /** Route halaman utama sebuah menu, null bila kunci bukan menu. */
    public static function route(string $key): ?string
    {
        return self::ITEMS[$key][2] ?? null;
    }
}
