<?php

use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('lists bookings without per-row relationship queries', function () {
    $customer = Customer::factory()->create();
    $resources = Resource::factory()->count(3)->create();
    $slots = Slot::factory()->count(60)->create();

    Booking::factory()->count(60)->sequence(
        fn ($sequence) => [
            'customer_id' => $customer->id,
            'resource_id' => $resources[$sequence->index % $resources->count()]->id,
            'slot_id' => $slots[$sequence->index]->id,
        ]
    )->create()->each(function (Booking $booking): void {
        BookingDocument::factory()->create(['booking_id' => $booking->id]);
    });

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($customer, 'sanctum')
        ->getJson(route('bookings.index', ['per_page' => 50]))
        ->assertOk()
        ->assertJsonCount(50, 'data')
        ->assertJsonPath('meta.per_page', 50)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'status',
                    'type',
                    'documents_count',
                    'customer' => ['id', 'name', 'email'],
                    'resource' => ['id', 'name', 'type'],
                    'slot' => ['id', 'date', 'start_time', 'end_time'],
                ],
            ],
        ]);

    expect($queries)->toBeLessThanOrEqual(7);
});
