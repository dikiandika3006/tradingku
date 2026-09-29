<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramPriceAlert extends Model
{
    protected $table = 'telegram_price_alerts';

    protected $fillable = [
        'symbol',
        'last_alert_price',
        'last_alert_time',
        'last_seen_at',
    ];

    protected $casts = [
        'last_alert_time' => 'datetime',
        'last_seen_at'    => 'datetime',
    ];

    /**
     * Berapa jam sejak aset ini terakhir terlihat di portofolio.
     *
     * null berarti belum pernah tercatat — baris lama dari sebelum kolom
     * last_seen_at ada, yang juga harus diperlakukan sebagai basi.
     */
    public function jamSejakTerlihat(): ?float
    {
        return $this->last_seen_at
            ? $this->last_seen_at->diffInMinutes(now()) / 60
            : null;
    }
}
