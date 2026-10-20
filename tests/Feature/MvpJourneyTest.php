<?php

use App\Exceptions\ApiConflictException;
use App\Exceptions\InvalidBookingStatusTransition;
use App\Jobs\CaptureBookingPayment;
use App\Jobs\SendBookingConfirmation;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Paymob\Laravel\Contracts\PaymobClientContract;
use Paymob\Laravel\DTO\CapturePaymentResponseDto;
use Paymob\Laravel\DTO\OrderResponseDto;
use Paymob\Laravel\DTO\PaymentKeyResponseDto;
use Paymob\Laravel\Models\Payment;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2030, 1, 1)->startOfDay());
});

function mvpPaymentClient(): PaymobClientContract
{
    $client = Mockery::mock(PaymobClientContract::class);
    $client->shouldReceive('registerOrder')->once()->andReturn(new OrderResponseDto(id: 987654));
    $client->shouldReceive('requestPaymentKey')->once()->andReturn(new PaymentKeyResponseDto(token: 'test-key'));
    $client->shouldReceive('paymentRedirectUrl')->once()->with('test-key')->andReturn('https://example.test/checkout');
    app()->instance(PaymobClientContract::class, $client);
    config(['paymob.integration_id' => 123]);

    return $client;
}

function mvpSignedWebhook(Payment $payment, int $transaction = 123456): array
{
    config(['paymob.hmac_secret' => 'test-secret', 'paymob.integration_id' => 123]);
    $obj = [
        'amount_cents' => (int) $payment->amount_cents, 'id' => $transaction,
        'order' => ['id' => (int) $payment->paymob_reference], 'success' => true,
        'currency' => 'EGP', 'integration_id' => 123,
    ];
    // Unspecified Paymob HMAC fields normalize to an empty string.
    $signature = hash_hmac('sha512', $obj['amount_cents'].'EGP'.$obj['id'].'123'.$obj['order']['id'].'true', 'test-secret');

    return ['obj' => $obj, 'hmac' => $signature];
}

it('walks through registration discovery booking payment capture viewing and cancellation', function () {
    Queue::fake();
    $resource = Resource::factory()->create(['price' => 250]);
    Resource::factory()->create(['status' => 'inactive']);
    $slot = Slot::factory()->create();
    $auth = $this->postJson('/api/register', [
        'name' => 'Demo Customer', 'email' => 'demo@example.test', 'password' => 'demo-password',
    ])->assertCreated()->assertJsonPath('success', true);
    $this->withToken($auth->json('token'));
    $this->getJson('/api/resources')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $resource->id);
    $url = route('resources.availability', ['resource' => $resource, 'start_date' => '2030-01-02', 'end_date' => '2030-01-02', 'timezone' => 'UTC']);
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.slot_id', $slot->id);
    $payload = ['resource_id' => $resource->id, 'slot_id' => $slot->id, 'type' => 'one-on-one'];
    $response = $this->postJson('/api/bookings', $payload, ['Idempotency-Key' => 'walkthrough'])->assertCreated();
    $id = $response->json('booking.id');
    $this->postJson('/api/bookings', $payload, ['Idempotency-Key' => 'walkthrough'])->assertCreated()->assertExactJson($response->json());
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/bookings/'.$id)->assertOk()->assertJsonPath('booking.status', 'pending');
    $this->getJson('/api/bookings')->assertOk()->assertJsonCount(1, 'data');
    $client = mvpPaymentClient();
    $this->postJson('/api/bookings/'.$id.'/pay')->assertOk()->assertJsonPath('amount_cents', 25000);
    $this->postJson('/api/bookings/'.$id.'/pay')->assertConflict();
    $this->postJson('/api/bookings/'.$id.'/update', ['status' => 'canceled'])->assertConflict();
    $payment = Payment::query()->firstOrFail();
    $webhook = mvpSignedWebhook($payment);
    $this->postJson('/api/payments/paymob/webhook', $webhook)->assertOk();
    $this->postJson('/api/payments/paymob/webhook', $webhook)->assertOk();
    Queue::assertPushed(CaptureBookingPayment::class, 2);
    $client->shouldReceive('capture')->once()->with(123456, 25000)->andReturn(new CapturePaymentResponseDto(['success' => true]));
    (new CaptureBookingPayment($payment->id, 123456))->handle($client);
    (new CaptureBookingPayment($payment->id, 123456))->handle($client);
    $this->getJson('/api/bookings/'.$id)->assertOk()->assertJsonPath('booking.status', 'confirmed');
    Queue::assertPushed(SendBookingConfirmation::class, 1);
    $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'captured']);
    $this->assertDatabaseHas('booking_status_events', ['booking_id' => $id, 'from_status' => 'pending', 'to_status' => 'confirmed']);
    $this->postJson('/api/bookings/'.$id.'/update', ['status' => 'canceled'])->assertOk()->assertJsonPath('booking.status', 'canceled');
    $this->postJson('/api/bookings/'.$id.'/update', ['status' => 'confirmed'])->assertConflict()->assertJsonPath('error.code', 'conflict');
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
});

it('protects resource and booking endpoints from anonymous and foreign customers', function () {
    $booking = Booking::factory()->create();
    $this->getJson('/api/resources')->assertUnauthorized();
    $this->getJson('/api/bookings/'.$booking->id)->assertUnauthorized();
    $this->actingAs(Customer::factory()->create(), 'sanctum');
    $this->getJson('/api/bookings/'.$booking->id)->assertForbidden();
    $this->postJson('/api/bookings/'.$booking->id.'/pay')->assertNotFound();
    $this->postJson('/api/bookings/'.$booking->id.'/update', ['status' => 'canceled'])->assertForbidden();
});

it('rejects structural changes even when their values are valid', function (string $field) {
    $booking = Booking::factory()->create(['status' => 'pending']);
    $value = match ($field) {
        'customer_id' => Customer::factory()->create()->id,
        'resource_id' => Resource::factory()->create()->id,
        'slot_id' => Slot::factory()->create()->id,
        'type' => 'group',
    };
    $this->actingAs($booking->customer, 'sanctum')->postJson('/api/bookings/'.$booking->id.'/update', [$field => $value, 'status' => 'canceled'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    expect($booking->refresh()->status)->toBe('pending');
})->with(['customer_id', 'resource_id', 'slot_id', 'type']);

it('rejects unavailable inventory and does not reserve an idempotency key', function (string $reason) {
    $resource = Resource::factory()->create(['status' => $reason === 'resource' ? 'inactive' : 'active']);
    $slot = Slot::factory()->create([
        'date' => $reason === 'past' ? now()->subDay()->toDateString() : now()->addDay()->toDateString(),
        'status' => $reason === 'slot' ? 'inactive' : 'active',
    ]);
    $this->actingAs(Customer::factory()->create(), 'sanctum')->postJson('/api/bookings', [
        'resource_id' => $resource->id, 'slot_id' => $slot->id,
    ], ['Idempotency-Key' => 'invalid-inventory'])->assertConflict();
    $this->assertDatabaseCount('bookings', 0);
    $this->assertDatabaseCount('idempotency_keys', 0);
})->with(['resource', 'slot', 'past']);

it('rechecks the persisted status instead of accepting stale models', function () {
    $booking = Booking::factory()->create(['status' => 'pending']);
    $stale = $booking->fresh();
    app(BookingService::class)->transitionStatus($booking, 'canceled');
    expect(fn () => app(BookingService::class)->transitionStatus($stale, 'confirmed'))->toThrow(InvalidBookingStatusTransition::class);
});

it('does not resurrect terminal bookings on capture or publish effects on rollback', function () {
    Queue::fake();
    $booking = Booking::factory()->create(['status' => 'pending']);
    try {
        DB::transaction(function () use ($booking) {
            $booking->markPaymobCaptured(new CapturePaymentResponseDto(['success' => true]));
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect($booking->refresh()->status)->toBe('pending');
    Queue::assertNotPushed(SendBookingConfirmation::class);
    app(BookingService::class)->transitionStatus($booking, 'canceled');
    expect(fn () => $booking->markPaymobCaptured(new CapturePaymentResponseDto(['success' => true])))
        ->toThrow(InvalidBookingStatusTransition::class);
});

it('rejects unsigned and amount mismatched payment callbacks', function () {
    Queue::fake();
    $booking = Booking::factory()->create(['status' => 'pending']);
    $payment = Payment::query()->create(['paymob_reference' => '987654', 'order_type' => Booking::class, 'order_id' => (string) $booking->id, 'amount_cents' => 100, 'status' => 'processing']);
    $payload = mvpSignedWebhook($payment);
    $this->postJson('/api/payments/paymob/webhook', array_replace($payload, ['hmac' => 'invalid']))->assertForbidden();
    $payment->amount_cents = 200;
    $this->postJson('/api/payments/paymob/webhook', mvpSignedWebhook($payment))->assertConflict();
    Queue::assertNothingPushed();
});

it('retries failed capture without a duplicate payment or early confirmation', function () {
    Queue::fake();
    $booking = Booking::factory()->create(['status' => 'pending']);
    $payment = Payment::query()->create(['paymob_reference' => '987654', 'order_type' => Booking::class, 'order_id' => (string) $booking->id, 'amount_cents' => 100, 'status' => 'processing']);
    $client = Mockery::mock(PaymobClientContract::class);
    $client->shouldReceive('capture')->once()->andThrow(new RuntimeException('gateway timeout'));
    $client->shouldReceive('capture')->once()->andReturn(new CapturePaymentResponseDto(['success' => true]));
    $job = new CaptureBookingPayment($payment->id, 123456);
    expect(fn () => $job->handle($client))->toThrow(RuntimeException::class);
    expect($booking->refresh()->status)->toBe('pending');
    Queue::assertNotPushed(SendBookingConfirmation::class);
    $job->handle($client);
    expect($booking->refresh()->status)->toBe('confirmed');
    $this->assertDatabaseCount('payments', 1);
    Queue::assertPushed(SendBookingConfirmation::class, 1);
});

it('uses server capacity and makes group availability reflect remaining seats', function () {
    $resource = Resource::factory()->create(['capacity' => 2]);
    $slot = Slot::factory()->create();
    $payload = ['resource_id' => $resource->id, 'slot_id' => $slot->id, 'type' => 'group', 'max_participants' => 999];
    $this->actingAs(Customer::factory()->create(), 'sanctum')->postJson('/api/bookings', $payload, ['Idempotency-Key' => 'group-1'])
        ->assertCreated()->assertJsonPath('booking.status', 'pending')->assertJsonPath('booking.max_participants', 2);
    $this->postJson('/api/bookings', $payload, ['Idempotency-Key' => 'duplicate-seat'])->assertConflict();
    $url = route('resources.availability', ['resource' => $resource, 'start_date' => '2030-01-02', 'end_date' => '2030-01-02', 'timezone' => 'UTC', 'type' => 'group']);
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs(Customer::factory()->create(), 'sanctum')->postJson('/api/bookings', $payload, ['Idempotency-Key' => 'group-2'])
        ->assertCreated()->assertJsonPath('booking.status', 'pending');
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs(Customer::factory()->create(), 'sanctum')->postJson('/api/bookings', $payload, ['Idempotency-Key' => 'group-3'])->assertConflict();
    $this->assertDatabaseCount('bookings', 2);
});

it('reserves every recurring occurrence as pending and rolls back on missing inventory', function () {
    $resource = Resource::factory()->create();
    $slots = collect(['2030-01-02', '2030-01-09', '2030-01-16'])->map(fn ($date) => Slot::factory()->create([
        'date' => $date, 'start_time' => '09:00:00', 'end_time' => '10:00:00',
    ]));
    $payload = ['resource_id' => $resource->id, 'slot_id' => $slots[0]->id, 'type' => 'recurring',
        'start_date' => '2030-01-02', 'end_date' => '2030-01-16', 'recurrence_rule' => 'weekly',
        'start_time' => '09:00', 'end_time' => '10:00'];
    $this->actingAs(Customer::factory()->create(), 'sanctum');
    $slots[2]->update(['status' => 'inactive']);
    $this->postJson('/api/bookings', $payload, ['Idempotency-Key' => 'series'])->assertConflict();
    $this->assertDatabaseCount('bookings', 0);
    $slots[2]->update(['status' => 'active']);
    $this->postJson('/api/bookings', $payload, ['Idempotency-Key' => 'series'])->assertCreated()->assertJsonPath('booking.status', 'pending');
    $this->getJson('/api/bookings')->assertOk()->assertJsonCount(3, 'data');
    expect(Booking::query()->where('status', 'pending')->count())->toBe(3);
    $this->actingAs(Customer::factory()->create(), 'sanctum')->postJson('/api/bookings', [
        'slot_id' => $slots[1]->id, 'resource_id' => $resource->id,
    ], ['Idempotency-Key' => 'occupied-occurrence'])->assertConflict();
});

it('does not offer inventory occupied through another resource or after its start time', function () {
    $resource = Resource::factory()->create();
    $slot = Slot::factory()->create();
    $other = Booking::factory()->create(['slot_id' => $slot->id, 'status' => 'pending']);
    $this->actingAs(Customer::factory()->create(), 'sanctum');
    $url = route('resources.availability', ['resource' => $resource, 'start_date' => '2030-01-02', 'end_date' => '2030-01-02', 'timezone' => 'UTC']);
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    app(BookingService::class)->transitionStatus($other, 'canceled');
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
    $this->travelTo(now()->addDays(2));
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
});

it('rejects null structural fields and a null booking type without changing inventory', function () {
    $booking = Booking::factory()->create(['status' => 'pending']);
    $this->actingAs($booking->customer, 'sanctum')->postJson('/api/bookings/'.$booking->id.'/update', [
        'status' => 'canceled', 'customer_id' => null,
    ])->assertUnprocessable();
    $this->postJson('/api/bookings', ['resource_id' => $booking->resource_id, 'slot_id' => $booking->slot_id, 'type' => null], [
        'Idempotency-Key' => 'null-type',
    ])->assertUnprocessable();
    expect($booking->refresh()->status)->toBe('pending');
});

it('rejects unsuccessful capture responses without confirming or recording capture', function () {
    Queue::fake();
    $booking = Booking::factory()->create(['status' => 'pending']);
    $payment = Payment::query()->create(['paymob_reference' => '987654', 'order_type' => Booking::class, 'order_id' => (string) $booking->id, 'amount_cents' => 100, 'status' => 'processing']);
    $client = Mockery::mock(PaymobClientContract::class);
    $client->shouldReceive('capture')->once()->andReturn(new CapturePaymentResponseDto(['success' => false]));
    expect(fn () => (new CaptureBookingPayment($payment->id, 123456))->handle($client))->toThrow(RuntimeException::class);
    expect(fn () => $booking->markPaymobCaptured(new CapturePaymentResponseDto(['success' => false])))
        ->toThrow(ApiConflictException::class);
    expect($booking->refresh()->status)->toBe('pending');
    expect($payment->refresh()->status)->toBe('processing');
    Queue::assertNotPushed(SendBookingConfirmation::class);
});
