<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoinglassVolumeSnapshot extends Model
{
    /** Timeframe yang dilacak, dari yang terpendek. */
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
        'price_change_percent_1h',
        'price_change_percent_4h',
        'price_change_percent_12h',
        'price_change_percent_24h',
        'price_change_percent_1w',
        'volume_change_percent_24h',
        'captured_at',
    ];

    protected $casts = [
        'captured_at'               => 'datetime',
        'price_usd'                 => 'float',
        'volume_usd_1h'             => 'float',
        'volume_usd_4h'             => 'float',
        'volume_usd_12h'            => 'float',
        'volume_usd_24h'            => 'float',
        'volume_usd_1w'             => 'float',
        'price_change_percent_1h'   => 'float',
        'price_change_percent_4h'   => 'float',
        'price_change_percent_12h'  => 'float',
        'price_change_percent_24h'  => 'float',
        'price_change_percent_1w'   => 'float',
        'volume_change_percent_24h' => 'float',
    ];

    /** Pengambilan terakhir untuk sebuah aset. */
    public function scopeLatestCapture($query, string $symbol = 'BTC')
    {
        $at = static::where('symbol', $symbol)->max('captured_at');

        return $query->where('symbol', $symbol)->where('captured_at', $at);
    }

    public function volumeFor(string $timeframe): ?float
    {
        return $this->{'volume_usd_' . $timeframe};
    }

    public function priceChangeFor(string $timeframe): ?float
    {
        return $this->{'price_change_percent_' . $timeframe};
    }
}
