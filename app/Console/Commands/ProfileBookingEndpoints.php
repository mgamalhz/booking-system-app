<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Slot;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class ProfileBookingEndpoints extends Command
{
    private ?string $activeEndpoint = null;

    /** @var array<string, array<string, mixed>> */
    private array $metrics = [];

    protected $signature = 'profile:booking-endpoints {--iterations=10} {--output=storage/app/profiling/latest.json}';

    protected $description = 'Run a repeatable HTTP workload and record endpoint telemetry';

    public function handle(Kernel $kernel): int
    {
        $iterations = max(1, (int) $this->option('iterations'));
        $customer = Customer::query()->where('email', 'profile@example.test')->first();
        if (! $customer) {
            $this->error('Run: php artisan db:seed --class=ProfilingDatasetSeeder');

            return self::FAILURE;
        }

        $token = $customer->createToken('profiling')->plainTextToken;
        $booking = Booking::query()->where('customer_id', $customer->id)->firstOrFail();
        $freeSlots = Slot::query()->whereDoesntHave('bookings')->limit($iterations + 1)->get();
        if ($freeSlots->count() < $iterations) {
            $this->error('The profiling dataset has too few free slots; reseed it.');

            return self::FAILURE;
        }

        DB::listen(function ($query): void {
            if ($this->activeEndpoint === null) {
                return;
            }
            $this->metrics[$this->activeEndpoint]['queries']++;
            $this->metrics[$this->activeEndpoint]['query_ms'] += $query->time;
        });
        Event::listen(CacheHit::class, function (): void {
            if ($this->activeEndpoint !== null) {
                $this->metrics[$this->activeEndpoint]['cache_hits']++;
            }
        });
        Event::listen(CacheMissed::class, function (): void {
            if ($this->activeEndpoint !== null) {
                $this->metrics[$this->activeEndpoint]['cache_misses']++;
            }
        });
        Event::listen(JobQueued::class, function (): void {
            if ($this->activeEndpoint !== null) {
                $this->metrics[$this->activeEndpoint]['jobs']++;
            }
        });
        Event::listen(ResponseReceived::class, function (): void {
            if ($this->activeEndpoint !== null) {
                $this->metrics[$this->activeEndpoint]['external_requests']++;
            }
        });

        for ($i = 0; $i < $iterations; $i++) {
            $this->measure($kernel, 'POST /api/login', Request::create('/api/login', 'POST', [
                'email' => 'profile@example.test', 'password' => 'profile-password',
            ]));

            $this->measure($kernel, 'POST /api/booking', Request::create('/api/booking', 'POST', [
                'customer_id' => $customer->id, 'resource_id' => $booking->resource_id,
                'slot_id' => $freeSlots[$i]->id, 'type' => 'one-on-one',
            ], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"]));

            $this->measure($kernel, 'POST /api/booking/{id}/update', Request::create("/api/booking/{$booking->id}/update", 'POST', [
                'status' => $i % 2 ? 'pending' : 'confirmed',
            ], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"]));
        }

        $this->activeEndpoint = null;
        $result = ['generated_at' => now()->toIso8601String(), 'iterations' => $iterations,
            'dataset' => ['customers' => Customer::count(), 'slots' => Slot::count(), 'bookings' => Booking::count()],
            'endpoints' => $this->summarize($this->metrics, $iterations)];
        $output = base_path((string) $this->option('output'));
        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0775, true);
        }
        file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->info("Profiling results written to {$output}");

        return self::SUCCESS;
    }

    private function measure(Kernel $kernel, string $name, Request $request): void
    {
        $this->metrics[$name] ??= ['latency_ms' => [], 'queries' => 0, 'query_ms' => 0.0,
            'cache_hits' => 0, 'cache_misses' => 0, 'jobs' => 0, 'external_requests' => 0, 'statuses' => []];
        $this->activeEndpoint = $name;
        $start = hrtime(true);
        $response = $kernel->handle($request);
        $this->metrics[$name]['latency_ms'][] = (hrtime(true) - $start) / 1_000_000;
        $this->metrics[$name]['statuses'][] = $response->getStatusCode();
        $kernel->terminate($request, $response);
        $this->activeEndpoint = null;
    }

    private function summarize(array $metrics, int $iterations): array
    {
        foreach ($metrics as &$row) {
            sort($row['latency_ms']);
            $row['latency_p50_ms'] = round($row['latency_ms'][(int) floor(($iterations - 1) * .50)], 2);
            $row['latency_p95_ms'] = round($row['latency_ms'][(int) floor(($iterations - 1) * .95)], 2);
            unset($row['latency_ms']);
            $row['queries_per_request'] = round($row['queries'] / $iterations, 2);
            $row['query_ms_per_request'] = round($row['query_ms'] / $iterations, 2);
            $row['statuses'] = array_values(array_unique($row['statuses']));
        }

        return $metrics;
    }
}
