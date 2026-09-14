<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('updates with one refresh instead of three redundant hydrated reads', function () {
    $customer = Customer::factory()->create();
    $resource = Resource::factory()->create();
    $slot = Slot::factory()->create();
    $booking = Booking::factory()->create([
        'customer_id' => $customer->id,
        'resource_id' => $resource->id,
        'slot_id' => $slot->id,
        'status' => 'pending',
    ]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $updated = app(BookingService::class)->updateExistingBooking($booking, ['status' => 'canceled']);

    expect($updated->status)->toBe('canceled')
        ->and($updated->relationLoaded('slot'))->toBeTrue()
        ->and($updated->relationLoaded('resource'))->toBeTrue()
        ->and($updated->relationLoaded('customer'))->toBeTrue()
        ->and($queries)->toBeLessThanOrEqual(8);
});
