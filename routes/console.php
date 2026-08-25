<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('telegram:check-price-alerts')->everyMinute();

// Monthly Portfolio Snapshot Engine
// 1. Cut-off resmi: kunci valuasi bulan berjalan tepat sebelum tengah malam akhir bulan.
Schedule::command('portfolio:snapshot --finalize')
    ->lastDayOfMonth('23:55')
    ->withoutOverlapping();

// 2. Jaring pengaman harian: kunci bulan lampau yang cut-off-nya terlewat,
//    sekaligus me-refresh snapshot LIVE bulan berjalan.
Schedule::command('portfolio:snapshot --backfill')
    ->dailyAt('00:10')
    ->withoutOverlapping();
