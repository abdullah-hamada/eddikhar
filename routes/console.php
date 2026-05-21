<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ledger:reconcile-payments')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

// Auto-fix minor ledger drift every night
Schedule::command('ledger:reconcile-balances --fix')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();
