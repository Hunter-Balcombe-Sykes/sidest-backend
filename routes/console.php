<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('comet:purge-soft-deletes')
    ->dailyAt('03:20');

Schedule::command('comet:prune-notifications', ['--days' => 30])
    ->dailyAt('03:25');
