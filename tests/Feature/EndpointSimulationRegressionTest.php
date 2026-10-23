<?php

use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2030, 1, 1)->startOfDay());
});

it('keeps the owned booking index and document counts within a fixed query budget', function () {
    $customer = Customer::factory()->create();
    $bookings = Booking::factory()->count(20)->for($customer)->create();
    BookingDocument::factory()->count(3)->create(['booking_id' => $bookings->last()->id]);
    Booking::factory()->create();
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $response = $this->actingAs($customer, 'sanctum')->getJson('/api/bookings?per_page=20')
        ->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 20)
        ->assertJsonPath('data.0.documents_count', 3)
        ->assertJsonPath('data.0.customer.id', $customer->id);
    expect($response->json('data.*.id'))->toBe($bookings->reverse()->pluck('id')->values()->all());
    expect($queries)->toBeLessThanOrEqual(6);
});

it('keeps all owned pending bookings and their full relations within a fixed query budget', function () {
    $customer = Customer::factory()->create();
    $pending = Booking::factory()->count(20)->for($customer)->create(['status' => 'pending']);
    Booking::factory()->for($customer)->create(['status' => 'confirmed']);
    Booking::factory()->create(['status' => 'pending']);
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $response = $this->actingAs($customer, 'sanctum')->getJson('/api/bookings/non-paid')
        ->assertOk()->assertJsonPath('success', true)->assertJsonCount(20, 'bookings')
        ->assertJsonStructure(['bookings' => ['*' => ['id', 'status', 'customer', 'resource', 'slot', 'duration']]]);
    expect(collect($response->json('bookings'))->pluck('id')->sort()->values()->all())
        ->toBe($pending->pluck('id')->sort()->values()->all());
    expect(collect($response->json('bookings'))->pluck('customer_id')->unique()->all())->toBe([$customer->id]);
    expect($queries)->toBeLessThanOrEqual(5);
});

it('checks slot occupancy in one query and preserves canceled and deleted availability', function () {
    $customer = Customer::factory()->create();
    $resource = Resource::factory()->create(['status' => 'active']);
    $slots = Slot::factory()->count(20)->create(['date' => '2030-01-02', 'status' => 'active']);
    foreach (['pending', 'confirmed', 'canceled', 'pending'] as $index => $status) {
        $booking = Booking::factory()->for($resource)->create(['slot_id' => $slots[$index]->id, 'status' => $status]);
        if ($index === 3) {
            $booking->delete();
        }
    }
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $response = $this->actingAs($customer, 'sanctum')->getJson(
        "/api/resources/{$resource->id}/availability?start_date=2030-01-02&end_date=2030-01-02&timezone=Africa%2FCairo"
    )->assertOk()->assertJsonCount(18, 'data')
        ->assertJsonStructure(['data' => ['*' => ['slot_id', 'starts_at', 'ends_at']]]);
    expect(collect($response->json('data'))->pluck('slot_id')->sort()->values()->all())
        ->toBe($slots->slice(2)->pluck('id')->sort()->values()->all());
    expect($queries)->toBeLessThanOrEqual(3);
});
