<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyPortfolioSnapshot extends Model
{
    protected $fillable = [
        'year_month',
        'cut_off_date',
        'ending_valuation_usdt',
        'ending_valuation_idr',
        'realized_pnl_usdt',
        'realized_pnl_idr',
        'floating_pnl_usdt',
        'floating_pnl_idr',
        'true_net_pnl_usdt',
        'true_net_pnl_idr',
        'nominal_growth_usdt',
        'nominal_growth_idr',
        'mom_growth_percent',
        'trades_count',
        'win_rate',
        'health_badge',
        'top_asset',
        'is_finalized'
    ];

    protected $casts = [
        'cut_off_date' => 'datetime',
        'ending_valuation_usdt' => 'float',
        'ending_valuation_idr' => 'float',
        'realized_pnl_usdt' => 'float',
        'realized_pnl_idr' => 'float',
        'floating_pnl_usdt' => 'float',
        'floating_pnl_idr' => 'float',
        'true_net_pnl_usdt' => 'float',
        'true_net_pnl_idr' => 'float',
        'nominal_growth_usdt' => 'float',
        'nominal_growth_idr' => 'float',
        'mom_growth_percent' => 'float',
        'win_rate' => 'float',
        'is_finalized' => 'boolean'
    ];
}
