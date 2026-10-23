<?php

// Deliberately degraded baseline vs restored application, never production profiling.
chdir(dirname(__DIR__, 3));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Customer;
use App\Models\Resource;
use App\Services\AvailabilityCacheKey;
use Carbon\CarbonImmutable;
use Faker\Generator;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$phase = $argv[1] ?? '';
if (! app()->environment('local') || ! in_array($phase, ['before', 'after-index', 'after-non-paid', 'after'], true)) {
    throw new RuntimeException('Use in APP_ENV=local: php docs/profiling/simulation/run.php before|after-index|after-non-paid|after');
}

CarbonImmutable::setTestNow('2030-01-01 00:00:00 UTC');
Carbon::setTestNow(CarbonImmutable::now());
$database = 'booking_simulation_20261023';
DB::statement("CREATE DATABASE IF NOT EXISTS `$database`");
config([
    'database.connections.mysql.database' => $database,
    'cache.prefix' => 'booking_simulation_20261023_',
    'telescope.enabled' => false,
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('mysql');
app(Generator::class)->seed(20261023);
Artisan::call('migrate', ['--force' => true]);
if (DB::table('customers')->count() === 0) {
    Artisan::call('db:seed', ['--class' => 'ProfilingDatasetSeeder', '--force' => true]);
}
$dataset = [];
foreach (['customers', 'resources', 'slots', 'bookings', 'booking_documents'] as $table) {
    $dataset[$table] = DB::table($table)->count();
}
if (array_values($dataset) !== [250, 50, 5000, 3000, 1500]) {
    throw new RuntimeException('Unexpected fixture; use a clean dedicated simulation database.');
}
$customer = Customer::where('email', 'profile@example.test')->firstOrFail();
$resource = Resource::where('status', 'active')->firstOrFail();
$start = CarbonImmutable::today()->addDays(290)->toDateString();
$end = CarbonImmutable::today()->addDays(320)->toDateString();
$paths = [
    'booking-index' => '/api/bookings?per_page=50',
    'non-paid' => '/api/bookings/non-paid',
    'availability' => "/api/resources/{$resource->id}/availability?".http_build_query([
        'start_date' => $start, 'end_date' => $end, 'timezone' => 'Africa/Cairo',
    ]),
];
$active = false;
$queries = [];
DB::listen(function ($query) use (&$active, &$queries): void {
    if ($active) {
        $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings, 'ms' => $query->time];
    }
});
$kernel = $app->make(Kernel::class);
$results = [];
DB::beginTransaction();
try {
    $token = $customer->createToken('simulation')->plainTextToken;
    // Keep Sanctum timestamp writes out of every sample, in both phases.
    DB::table('personal_access_tokens')->update(['last_used_at' => now()]);
    for ($iteration = -2; $iteration < 20; $iteration++) {
        foreach ($paths as $name => $path) {
            Auth::forgetGuards();
            // Force a real resolver run, clearing only this fixture's exact key.
            if ($name === 'availability') {
                Cache::tags([AvailabilityCacheKey::scheduleTag(), AvailabilityCacheKey::resourceTag($resource->id)])
                    ->forget(AvailabilityCacheKey::make($resource->id, $start, $end, 'Africa/Cairo', []));
            }
            $request = Request::create($path, 'GET', [], [], [], [
                'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ]);
            $queries = [];
            $active = true;
            $started = hrtime(true);
            $response = $kernel->handle($request);
            $elapsed = (hrtime(true) - $started) / 1e6;
            $active = false;
            $kernel->terminate($request, $response);
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException("Unexpected status for $path: ".$response->getContent());
            }
            if ($iteration < 0) {
                continue;
            }
            $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $results[$name]['request'] = $path;
            $results[$name]['samples'][] = [
                'latency_ms' => round($elapsed, 3), 'queries' => count($queries),
                'sql_ms' => round(array_sum(array_column($queries, 'ms')), 3),
                'status' => $response->getStatusCode(), 'response_sha256' => $hash,
                'bytes' => strlen($response->getContent()),
                'items' => count($payload['data'] ?? $payload['bookings'] ?? []),
            ];
            if ($iteration === 0) {
                $results[$name]['query_groups'] = [];
                foreach ($queries as $query) {
                    $fingerprint = preg_replace('/\b\d+\b/', '?', $query['sql']);
                    if (! isset($results[$name]['query_groups'][$fingerprint])) {
                        $results[$name]['query_groups'][$fingerprint] = $query + ['executions' => 0];
                    }
                    $results[$name]['query_groups'][$fingerprint]['executions']++;
                }
            }
        }
    }
} finally {
    $active = false;
    DB::rollBack();
}
foreach ($results as $name => &$result) {
    $latencies = array_column($result['samples'], 'latency_ms');
    sort($latencies);
    $result['p50_ms'] = $latencies[9];
    $result['p95_ms'] = $latencies[18];
    $result['queries_per_request'] = array_sum(array_column($result['samples'], 'queries')) / 20;
    $result['sql_ms_per_request'] = round(array_sum(array_column($result['samples'], 'sql_ms')) / 20, 3);
    $result['response_hashes'] = array_values(array_unique(array_column($result['samples'], 'response_sha256')));
    foreach ($result['query_groups'] as &$query) {
        $query['explain'] = DB::select('EXPLAIN '.$query['sql'], $query['bindings']);
    }
    unset($query);
    $result['query_groups'] = array_values($result['query_groups']);
    echo "$name: p50={$result['p50_ms']} ms; p95={$result['p95_ms']} ms; queries={$result['queries_per_request']}\n";
}
unset($result);
if ($phase !== 'before') {
    $baseline = json_decode(file_get_contents(__DIR__.'/before.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($results as $name => $result) {
        if ($result['request'] !== $baseline['results'][$name]['request'] || $result['response_hashes'] !== $baseline['results'][$name]['response_hashes']) {
            throw new RuntimeException("Response compatibility failed for $name");
        }
    }
    echo "All request paths and decoded JSON response hashes match the baseline.\n";
}
file_put_contents(__DIR__."/$phase.json", json_encode([
    'simulation' => true, 'phase' => $phase, 'measured_at_utc' => gmdate(DATE_ATOM),
    'frozen_application_clock' => now()->toIso8601String(), 'php' => PHP_VERSION,
    'mysql' => DB::selectOne('select version() as version')->version,
    'database' => $database, 'dataset' => $dataset, 'warmups' => 2, 'iterations' => 20,
    'cache' => config('cache.default'), 'telescope' => config('telescope.enabled'),
    'conditions' => 'Serial HTTP-kernel latency; fresh bearer auth per request; cold availability cache; same fixture/clock; token writes rolled back; excludes setup, cache reset, network and termination.',
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
