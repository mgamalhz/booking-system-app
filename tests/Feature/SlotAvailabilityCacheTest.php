<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use App\Repositories\Interfaces\SlotAvailabilityRepositoryInterface;
use App\Services\AvailabilityCacheInvalidator;
use App\Services\AvailabilityCacheKey;
use App\Services\SlotAvailabilityService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('booking.availability_cache.ttl_seconds', 120);
    Cache::store('array')->flush();
});

function availabilityUrl(Resource $resource, array $overrides = []): string
{
    return route('resources.availability', $resource).'?'.http_build_query(array_merge([
        'start_date' => '2030-01-01',
        'end_date' => '2030-01-02',
        'timezone' => 'UTC',
    ], $overrides));
}

test('availability misses then returns the cached slots on a hit', function () {
    app()->instance(AvailabilityCacheInvalidator::class, Mockery::mock(AvailabilityCacheInvalidator::class)->shouldIgnoreMissing());
    $resource = Resource::factory()->create(['status' => 'active']);
    Slot::withoutEvents(fn () => Slot::factory()->create([
        'date' => '2030-01-01',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
    ]));

    Log::spy();
    $availability = app(SlotAvailabilityService::class);
    expect($availability->forResource($resource, '2030-01-01', '2030-01-02', 'UTC'))->toHaveCount(1);

    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'from "slots"') || str_contains($query->sql, 'from `slots`')) {
            $queries++;
        }
    });

    expect($availability->forResource($resource, '2030-01-01', '2030-01-02', 'UTC'))->toHaveCount(1);

    expect($queries)->toBe(1);
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'availability_cache.access' && $context['result'] === 'hit');
});

test('booking creation and cancellation invalidate the resource availability tag', function () {
    $customer = Customer::factory()->create();
    $resource = Resource::factory()->create(['status' => 'active']);
    $slot = Slot::withoutEvents(fn () => Slot::factory()->create(['date' => '2030-01-01']));

    $this->actingAs($customer, 'sanctum')->getJson(availabilityUrl($resource))->assertJsonCount(1, 'data');

    $booking = Booking::factory()->create([
        'customer_id' => $customer->id,
        'resource_id' => $resource->id,
        'slot_id' => $slot->id,
        'status' => 'confirmed',
    ]);

    $this->actingAs($customer, 'sanctum')->getJson(availabilityUrl($resource))->assertJsonCount(0, 'data');

    $booking->update(['status' => 'canceled']);

    $this->actingAs($customer, 'sanctum')->getJson(availabilityUrl($resource))->assertJsonCount(1, 'data');
});

test('schedule updates invalidate cached availability for every resource through versioning', function () {
    $customer = Customer::factory()->create();
    $resource = Resource::factory()->create(['status' => 'active']);
    $slot = Slot::factory()->create(['date' => '2030-01-01', 'status' => 'active']);

    $this->actingAs($customer, 'sanctum')->getJson(availabilityUrl($resource))->assertJsonCount(1, 'data');
    $slot->update(['status' => 'inactive']);
    $this->actingAs($customer, 'sanctum')->getJson(availabilityUrl($resource))->assertJsonCount(0, 'data');
});

test('cache failure falls back to the database and returns correct availability', function () {
    Cache::extend('broken-availability', fn () => new CacheRepository(new class extends ArrayStore
    {
        public function get($key): mixed
        {
            throw new RuntimeException('Redis unavailable');
        }
    }));
    config()->set('cache.default', 'broken-availability');

    $resource = Resource::factory()->create(['status' => 'active']);
    Slot::factory()->create(['date' => '2030-01-01']);

    $result = app(SlotAvailabilityService::class)->forResource($resource, '2030-01-01', '2030-01-02', 'UTC');

    expect($result)->toHaveCount(1);
});

test('repository failures are not mistaken for cache failures or retried', function () {
    $resource = Resource::factory()->create(['status' => 'active']);
    $repository = Mockery::mock(SlotAvailabilityRepositoryInterface::class);
    $repository->shouldReceive('availableForResource')
        ->once()
        ->andThrow(new RuntimeException('Database unavailable'));
    app()->instance(SlotAvailabilityRepositoryInterface::class, $repository);

    expect(fn () => app(SlotAvailabilityService::class)
        ->forResource($resource, '2030-01-01', '2030-01-02', 'UTC'))
        ->toThrow(RuntimeException::class, 'Database unavailable');
});

test('a stale availability response is never the final booking authority', function () {
    app()->instance(AvailabilityCacheInvalidator::class, Mockery::mock(AvailabilityCacheInvalidator::class)->shouldIgnoreMissing());
    $customer = Customer::factory()->create();
    $resource = Resource::factory()->create(['status' => 'active']);
    $slot = Slot::withoutEvents(fn () => Slot::factory()->create(['date' => '2030-01-01']));

    $availability = app(SlotAvailabilityService::class);
    expect($availability->forResource($resource, '2030-01-01', '2030-01-02', 'UTC'))->toHaveCount(1);

    // Simulate a missed invalidation by writing directly to the database. The
    // cached read is deliberately stale, but booking creation must still check DB.
    Booking::query()->insert([
        'customer_id' => $customer->id,
        'resource_id' => $resource->id,
        'slot_id' => $slot->id,
        'status' => 'confirmed',
        'type' => 'one-on-one',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($availability->forResource($resource, '2030-01-01', '2030-01-02', 'UTC'))->toHaveCount(1);

    $this->actingAs($customer, 'sanctum')->postJson(route('bookings.store'), [
        'customer_id' => $customer->id,
        'resource_id' => $resource->id,
        'slot_id' => $slot->id,
        'type' => 'one-on-one',
    ])->assertUnprocessable();

    expect(Booking::query()->where('slot_id', $slot->id)->count())->toBe(1);
});

test('cache key changes for every availability dimension', function () {
    $base = AvailabilityCacheKey::make(1, '2030-01-01', '2030-01-02', 'UTC', [], 'v1', 1);

    expect(AvailabilityCacheKey::make(2, '2030-01-01', '2030-01-02', 'UTC', [], 'v1', 1))->not->toBe($base)
        ->and(AvailabilityCacheKey::make(1, '2030-01-02', '2030-01-03', 'UTC', [], 'v1', 1))->not->toBe($base)
        ->and(AvailabilityCacheKey::make(1, '2030-01-01', '2030-01-02', 'Africa/Cairo', [], 'v1', 1))->not->toBe($base)
        ->and(AvailabilityCacheKey::make(1, '2030-01-01', '2030-01-02', 'UTC', ['starts_after' => '09:00'], 'v1', 1))->not->toBe($base)
        ->and(AvailabilityCacheKey::make(1, '2030-01-01', '2030-01-02', 'UTC', [], 'v2', 1))->not->toBe($base)
        ->and(AvailabilityCacheKey::make(1, '2030-01-01', '2030-01-02', 'UTC', [], 'v1', 2))->not->toBe($base);
});
