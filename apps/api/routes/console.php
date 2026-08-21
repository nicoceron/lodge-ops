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

Schedule::command('payments:expire-requests')
    ->name('payments:expire-requests')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('payments:recover-refunds --older-than=15 --limit=100')
    ->name('payments:recover-refunds')
    ->everyTenMinutes()
    ->withoutOverlapping(15)
    ->onOneServer();

Schedule::command('payments:reconcile-in-person --older-than=2 --limit=100')
    ->name('payments:reconcile-in-person')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('direct-booking:maintain --batch=100')
    ->name('direct-booking:expire')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('direct-booking:maintain --batch=100 --cleanup')
    ->name('direct-booking:cleanup')
    ->dailyAt('03:10')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('integrations:heartbeat')
    ->name('integrations:heartbeat')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
