<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

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
    config()->set('database.default', 'sqlite');
    config()->set('telescope.storage.database.connection', 'sqlite');
    DB::setDefaultConnection('sqlite');
    config()->set('cache.default', 'file');
    config()->set('cache.stores.file.path', $cachePath);
    config()->set('cache.stores.file.lock_path', $cachePath);
    config()->set('booking.lock.wait_seconds', 0);
    config()->set('booking.lock.ttl_seconds', 10);

    DB::purge('sqlite');
    DB::reconnect('sqlite');
    app('cache')->forgetDriver('file');
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
        Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);

        $customer = Customer::factory()->create();
        $resource = Resource::factory()->create();
        $slot = Slot::factory()->create();
        $token = $customer->createToken('concurrent-booking-test')->plainTextToken;
        $payload = bookingCreationLockPayload($customer, $resource, $slot);
        $worker = base_path('tests/Support/PostBookingRequest.php');
        $encodedPayload = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $processes = [
            new Process([PHP_BINARY, $worker, $databasePath, $cachePath, $token, $encodedPayload], timeout: 10),
            new Process([PHP_BINARY, $worker, $databasePath, $cachePath, $token, $encodedPayload], timeout: 10),
        ];

        foreach ($processes as $process) {
            $process->start();
        }

        $responses = collect($processes)->map(function (Process $process) {
            $process->wait();

            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        });

        $statuses = collect($responses)->pluck('status')->sort()->values()->all();

        expect($statuses[0])->toBe(201);
        expect($statuses[1])->toBeIn([409, 422]);
        expect(Booking::query()->where('slot_id', $slot->id)->count())->toBe(1);
    } finally {
        DB::disconnect('sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.default', 'sqlite');
        config()->set('telescope.storage.database.connection', 'sqlite');
        DB::setDefaultConnection('sqlite');
        RefreshDatabaseState::$migrated = false;

        if (file_exists($databasePath)) {
            unlink($databasePath);
        }
    }
});
