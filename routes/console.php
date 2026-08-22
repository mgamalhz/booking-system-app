<?php

use App\Jobs\SendBookingReminder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');



Schedule::useCache('database');

Schedule::command('idempotency-keys:prune')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('s3:cleanup-documents')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::job(new SendBookingReminder)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
