<?php

use App\Jobs\SendBookingReminder;

Schedule::useCache('database');

Schedule::command('s3:cleanup-documents')
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::job(new SendBookingReminder)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
