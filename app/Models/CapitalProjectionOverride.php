<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapitalProjectionOverride extends Model
{
    /** Kolom nominal yang boleh ditimpa manual. */
    public const FIELDS = ['top_up', 'return_idr', 'alokasi_btc', 'alokasi_alt'];

    protected $fillable = [
        'year_month',
        'top_up',
        'return_idr',
        'alokasi_btc',
        'alokasi_alt',
    ];

    protected $casts = [
        'top_up'      => 'float',
        'return_idr'  => 'float',
        'alokasi_btc' => 'float',
        'alokasi_alt' => 'float',
    ];

    /** Baris ini tidak menimpa apa pun lagi. */
    public function kosong(): bool
    {
        foreach (self::FIELDS as $f) {
            if ($this->{$f} !== null) {
                return false;
            }
        }

        return true;
    }
}
