<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestorReport extends Model
{
    protected $fillable = [
        'period_start',
        'period_end',
        'start_portfolio_value',
        'end_portfolio_value',
        'allocation',
        'usdt_idr_rate',
        'btc_start_price',
        'btc_end_price',
        'portfolio_note',
        'manager_notes',
    ];

    protected $casts = [
        'period_start'          => 'date',
        'period_end'            => 'date',
        'start_portfolio_value' => 'float',
        'end_portfolio_value'   => 'float',
        'allocation'            => 'array',
        'usdt_idr_rate'         => 'float',
        'btc_start_price'       => 'float',
        'btc_end_price'         => 'float',
    ];

    public function title(): string
    {
        return 'Investor Report - ' . $this->period_end->locale('id')->translatedFormat('d F Y');
    }
}
