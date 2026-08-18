<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('outbox:publish --batch=100')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('reservation-holds:expire --batch=100')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('reservation-milestones:dispatch')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('artifacts:purge-expired --batch=100')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();
