<?php

use App\Jobs\SendBookingReminder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('idempotency-keys:prune', function () {
    $deleted = DB::table('idempotency_keys')
        ->where('created_at', '<', now()->subDay())
        ->delete();

    $this->info("Pruned {$deleted} idempotency key records.");
})->purpose('Prune idempotency key records older than 24 hours');

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
