<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BookingDocument;
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

        DB::listen(function ($query): void {
            if ($this->activeEndpoint === null) {
                return;
            }
            $this->metrics[$this->activeEndpoint]['queries']++;
            $this->metrics[$this->activeEndpoint]['query_ms'] += $query->time;
            $fingerprint = $this->queryFingerprint($query->sql);
            $this->metrics[$this->activeEndpoint]['query_fingerprints'][$fingerprint] =
                ($this->metrics[$this->activeEndpoint]['query_fingerprints'][$fingerprint] ?? 0) + 1;
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
            $this->measure($kernel, 'GET /api/booking?profile_bottleneck=1', Request::create(
                '/api/booking?profile_bottleneck=1&per_page=50',
                'GET',
                [],
                [],
                [],
                ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"]
            ));

            $this->measure($kernel, 'GET /api/booking', Request::create(
                '/api/booking?per_page=50',
                'GET',
                [],
                [],
                [],
                ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"]
            ));
        }

        $this->activeEndpoint = null;
        $result = ['generated_at' => now()->toIso8601String(), 'iterations' => $iterations,
            'dataset' => [
                'customers' => Customer::count(),
                'slots' => Slot::count(),
                'bookings' => Booking::count(),
                'booking_documents' => BookingDocument::count(),
            ],
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
            'cache_hits' => 0, 'cache_misses' => 0, 'jobs' => 0, 'external_requests' => 0, 'statuses' => [],
            'memory_usage_bytes' => [], 'memory_peak_bytes' => [], 'query_fingerprints' => []];
        $this->activeEndpoint = $name;
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $memoryStart = memory_get_usage(true);
        $start = hrtime(true);
        $response = $kernel->handle($request);
        $this->metrics[$name]['latency_ms'][] = (hrtime(true) - $start) / 1_000_000;
        $this->metrics[$name]['memory_usage_bytes'][] = max(0, memory_get_usage(true) - $memoryStart);
        $this->metrics[$name]['memory_peak_bytes'][] = memory_get_peak_usage(true);
        $this->metrics[$name]['statuses'][] = $response->getStatusCode();
        $kernel->terminate($request, $response);
        $this->activeEndpoint = null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $metrics
     * @return array<string, array<string, mixed>>
     */
    private function summarize(array $metrics, int $iterations): array
    {
        foreach ($metrics as &$row) {
            $row['latency_total_ms'] = round(array_sum($row['latency_ms']), 2);
            sort($row['latency_ms']);
            $row['latency_p50_ms'] = round($row['latency_ms'][(int) floor(($iterations - 1) * .50)], 2);
            $row['latency_p95_ms'] = round($row['latency_ms'][(int) floor(($iterations - 1) * .95)], 2);
            unset($row['latency_ms']);
            $row['memory_usage_peak_mb'] = round(max($row['memory_usage_bytes']) / 1024 / 1024, 2);
            $row['memory_peak_mb'] = round(max($row['memory_peak_bytes']) / 1024 / 1024, 2);
            unset($row['memory_usage_bytes'], $row['memory_peak_bytes']);
            $row['queries_per_request'] = round($row['queries'] / $iterations, 2);
            $row['query_ms_per_request'] = round($row['query_ms'] / $iterations, 2);
            $row['statuses'] = array_values(array_unique($row['statuses']));
            arsort($row['query_fingerprints']);
            $row['duplicated_query_fingerprints'] = array_slice(
                array_filter($row['query_fingerprints'], fn (int $count): bool => $count > $iterations),
                0,
                10,
                true
            );
            unset($row['query_fingerprints']);
        }

        return $metrics;
    }

    private function queryFingerprint(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', strtolower($sql)) ?? $sql;
        $sql = preg_replace('/\b\d+\b/', '?', $sql) ?? $sql;

        return trim($sql);
    }
}
