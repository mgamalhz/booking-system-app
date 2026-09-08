<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('booking.lock.wait_seconds', 0);
    config()->set('booking.lock.ttl_seconds', 10);
});

function bookingCreationLockPayload(Customer $customer, Resource $resource, Slot $slot): array
{
    return [
        'customer_id' => $customer->id,
        'resource_id' => $resource->id,
        'slot_id' => $slot->id,
        'type' => 'one-on-one',
    ];
}

function useSharedBookingCreationStore(string $databasePath, string $cachePath): void
{
    config()->set('database.connections.sqlite.database', $databasePath);
    config()->set('cache.default', 'file');
    config()->set('cache.stores.file.path', $cachePath);
    config()->set('cache.stores.file.lock_path', $cachePath);
    config()->set('booking.lock.wait_seconds', 0);
    config()->set('booking.lock.ttl_seconds', 10);

    DB::purge('sqlite');
    DB::reconnect('sqlite');
    app('cache')->forgetDriver('file');
}

function postBookingThroughHttpKernel(string $databasePath, string $cachePath, string $token, array $payload): array
{
    useSharedBookingCreationStore($databasePath, $cachePath);

    $request = Illuminate\Http\Request::create(
        '/api/booking',
        'POST',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ],
        content: json_encode($payload, JSON_THROW_ON_ERROR),
    );

    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return [
        'status' => $response->getStatusCode(),
        'json' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
    ];
}

test('it returns conflict when another booking attempt already holds the slot lock', function () {
    $customer = Customer::factory()->create();
    $resource = Resource::factory()->create();
    $slot = Slot::factory()->create();
    $lock = Cache::lock("slot:{$slot->id}:book", 10);

    expect($lock->get())->toBeTrue();

    try {
        $this->actingAs($customer, 'sanctum')
            ->postJson(route('bookings.store'), bookingCreationLockPayload($customer, $resource, $slot))
            ->assertConflict()
            ->assertJson([
                'success' => false,
                'message' => 'This slot is currently being booked. Please try again shortly.',
            ]);

        expect(Booking::query()->where('slot_id', $slot->id)->doesntExist())->toBeTrue();
    } finally {
        $lock->release();
    }
});

test('it does not block bookings for different slots while one slot lock is held', function () {
    $customer = Customer::factory()->create();
    $resource = Resource::factory()->create();
    $lockedSlot = Slot::factory()->create();
    $availableSlot = Slot::factory()->create();

    Cache::lock("slot:{$lockedSlot->id}:book", 10)->block(0, function () use ($customer, $resource, $availableSlot) {
        $this->actingAs($customer, 'sanctum')
            ->postJson(route('bookings.store'), bookingCreationLockPayload($customer, $resource, $availableSlot))
            ->assertCreated();
    });

    $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.store'), bookingCreationLockPayload($customer, $resource, $lockedSlot))
        ->assertCreated();

    expect(Booking::query()->whereIn('slot_id', [$lockedSlot->id, $availableSlot->id])->count())->toBe(2);
});

test('it only creates one booking when concurrent requests target the same slot', function () {
    $databasePath = database_path('booking-lock-'.uniqid().'.sqlite');
    $cachePath = storage_path('framework/cache/booking-lock-'.uniqid());

    touch($databasePath);

    try {
        useSharedBookingCreationStore($databasePath, $cachePath);
        Artisan::call('migrate:fresh', ['--force' => true]);

        $customer = Customer::factory()->create();
        $resource = Resource::factory()->create();
        $slot = Slot::factory()->create();
        $token = $customer->createToken('concurrent-booking-test')->plainTextToken;
        $payload = bookingCreationLockPayload($customer, $resource, $slot);

        $responses = Concurrency::driver('process')->run([
            fn () => postBookingThroughHttpKernel($databasePath, $cachePath, $token, $payload),
            fn () => postBookingThroughHttpKernel($databasePath, $cachePath, $token, $payload),
        ], 10);

        $statuses = collect($responses)->pluck('status')->sort()->values()->all();

        expect($statuses)->toBe([201, 422]);
        expect(Booking::query()->where('slot_id', $slot->id)->count())->toBe(1);
    } finally {
        if (file_exists($databasePath)) {
            unlink($databasePath);
        }
    }
});
