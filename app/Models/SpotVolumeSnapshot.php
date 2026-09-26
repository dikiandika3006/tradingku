<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpotVolumeSnapshot extends Model
{
    /** Jendela waktu yang direkam, dari yang terpendek. */
    public const TIMEFRAMES = ['1h', '4h', '12h', '24h', '1w'];

    /** Label yang ditampilkan ke pengguna. */
    public const TIMEFRAME_LABELS = [
        '1h'  => '1 Jam',
        '4h'  => '4 Jam',
        '12h' => '12 Jam',
        '24h' => '24 Jam',
        '1w'  => '7 Hari',
    ];

    protected $fillable = [
        'symbol',
        'exchange_name',
        'pair',
        'price_usd',
        'volume_usd_1h',
        'volume_usd_4h',
        'volume_usd_12h',
        'volume_usd_24h',
        'volume_usd_1w',
        'captured_at',
        'captured_date',
    ];

    protected $casts = [
        'captured_at'    => 'datetime',
        'captured_date'  => 'date',
        'price_usd'      => 'float',
        'volume_usd_1h'  => 'float',
        'volume_usd_4h'  => 'float',
        'volume_usd_12h' => 'float',
        'volume_usd_24h' => 'float',
        'volume_usd_1w'  => 'float',
    ];

    /** Baris dari rekaman harian terakhir sebuah aset. */
    public function scopeLatestDay($query, string $symbol = 'BTC')
    {
        $tanggal = static::where('symbol', $symbol)->max('captured_date');

        return $query->where('symbol', $symbol)->where('captured_date', $tanggal);
    }

    /**
     * Akumulasi volume seluruh bursa per hari, urut dari yang terbaru.
     *
     * @return \Illuminate\Support\Collection
     */
    public static function dailyTotals(string $symbol = 'BTC', int $days = 30)
    {
        $kolom = array_map(
            fn($tf) => "SUM(volume_usd_{$tf}) AS volume_usd_{$tf}",
            self::TIMEFRAMES
        );

        return static::where('symbol', $symbol)
            ->selectRaw('captured_date, COUNT(*) AS exchange_count, ' . implode(', ', $kolom))
            ->groupBy('captured_date')
            ->orderByDesc('captured_date')
            ->limit($days)
            ->get();
    }

    public function volumeFor(string $timeframe): ?float
    {
        return $this->{'volume_usd_' . $timeframe};
    }
}
