<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestorTransaction extends Model
{
    public const TYPES = [
        'initial_capital' => 'Initial Capital',
        'top_up'          => 'Top Up',
        'withdrawal'      => 'Penarikan',
    ];

    protected $fillable = [
        'investor_id',
        'date',
        'type',
        'amount',
        'notes',
    ];

    protected $casts = [
        'date'   => 'date',
        'amount' => 'float',
    ];

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    /** Nominal bertanda: penarikan mengurangi dana. */
    public function signedAmount(): float
    {
        return $this->type === 'withdrawal' ? -$this->amount : $this->amount;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
