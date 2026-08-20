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
    ->name('reservation-milestones:dispatch')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('communications:sweep-delivery-events --batch=100')
    ->name('communications:sweep-delivery-events')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('horizon:snapshot')
    ->name('horizon:snapshot')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
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
