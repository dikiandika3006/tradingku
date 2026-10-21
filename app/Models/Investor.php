<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investor extends Model
{
    protected $fillable = [
        'code',
        'name',
        'join_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'join_date' => 'date',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(InvestorTransaction::class);
    }

    /** Kode berikutnya dengan pola INV-001, INV-002, ... */
    public static function nextCode(): string
    {
        $max = static::pluck('code')
            ->map(fn ($c) => (int) preg_replace('/\D/', '', $c))
            ->max() ?? 0;

        return sprintf('INV-%03d', $max + 1);
    }
}
