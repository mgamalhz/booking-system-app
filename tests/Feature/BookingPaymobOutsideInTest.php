<?php

use App\Jobs\SendBookingConfirmation;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Paymob\Laravel\Contracts\PaymobClientContract;
use Paymob\Laravel\Jobs\ProcessPaymobPayment;
use Paymob\Laravel\Models\Payment;
use Paymob\Laravel\Models\PaymobWebhookEvent;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    config()->set('queue.default', 'database');
    config()->set('paymob.api_key', 'test-api-key');
    config()->set('paymob.integration_id', 123456);
    config()->set('paymob.iframe_id', 456789);
    config()->set('paymob.base_url', 'https://accept.paymob.test');
    config()->set('paymob.hmac_secret', 'test-hmac-secret');

    Cache::setDefaultDriver('array');
    app()->instance(Repository::class, Cache::store('array'));
    Worker::$pausable = false;
    Worker::$restartable = false;

    Cache::forget('paymob_token');
    Http::preventStrayRequests();
});

function paymobBaseUrl(): string
{
    return rtrim((string) config('paymob.base_url'), '/');
}

function paymobBookingPayload(Customer $customer, Resource $resource, Slot $slot): array
{
    return [
        'customer_id' => $customer->id,
        'resource_id' => $resource->id,
        'slot_id' => $slot->id,
        'type' => 'one-on-one',
    ];
}

/**
 * @return array{0: Customer, 1: resource, 2: Slot}
 */
function paymobBookingActors(): array
{
    $customer = Customer::factory()->create([
        'name' => 'Yasser Ali',
        'email' => 'yasser@example.com',
        'phone' => '01012345678',
        'address' => '1 Nile Street',
        'city' => 'Cairo',
        'state' => 'Cairo',
        'zip' => '11511',
        'country' => 'EG',
    ]);
    $resource = Resource::factory()->create([
        'name' => 'Meeting Room',
        'price' => 250,
    ]);
    $slot = Slot::factory()->create();

    return [$customer, $resource, $slot];
}

function fakeSuccessfulPaymobStartAndCapture(int $orderId = 111222333, string $paymentToken = 'payment-token'): void
{
    Http::fake([
        paymobBaseUrl().'/api/auth/tokens' => Http::response(['token' => 'auth-token']),
        paymobBaseUrl().'/api/ecommerce/orders' => Http::response([
            'id' => $orderId,
            'created_at' => '2026-09-09T12:00:00.000000',
        ]),
        paymobBaseUrl().'/api/acceptance/payment_keys' => Http::response(['token' => $paymentToken]),
        paymobBaseUrl().'/api/acceptance/capture?token=auth-token' => Http::response([
            'id' => 987654321,
            'success' => true,
            'captured' => true,
        ]),
    ]);
}

function fakeTimedOutPaymobOrderCreation(): void
{
    Http::fake([
        paymobBaseUrl().'/api/auth/tokens' => Http::response(['token' => 'auth-token']),
        paymobBaseUrl().'/api/ecommerce/orders' => Http::response(['detail' => 'Gateway timeout'], 504),
    ]);
}

function confirmBookingThroughHttp(Customer $customer, Booking $booking)
{
    return test()->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.update', $booking), ['status' => 'confirmed']);
}

function runQueuedJob(string $queue = 'default'): void
{
    app('queue.worker')->shouldQuit = false;
    app('queue.worker')->setCache(app('cache')->store('array'));
    Worker::$pausable = false;
    Worker::$restartable = false;

    test()->artisan("queue:work database --queue={$queue} --once --tries=1")->assertExitCode(0);
}

function runQueuedJobsUntilEmpty(string $queues): void
{
    app('queue.worker')->shouldQuit = false;
    app('queue.worker')->setCache(app('cache')->store('array'));
    Worker::$pausable = false;
    Worker::$restartable = false;

    test()->artisan("queue:work database --queue={$queues} --stop-when-empty --tries=1")->assertExitCode(0);
}

function processPaymobCapture(Booking $booking, int $transactionId = 987654321, int $amountCents = 25000): void
{
    (new ProcessPaymobPayment($booking, $transactionId, $amountCents))
        ->handle(app(PaymobClientContract::class));
}

function signedPaymobWebhookPayload(int $orderId = 111222333, int $transactionId = 987654321, int $amountCents = 25000): array
{
    $object = [
        'amount_cents' => $amountCents,
        'created_at' => '2026-09-09T12:30:00.000000',
        'currency' => 'EGP',
        'error_occured' => false,
        'has_parent_transaction' => false,
        'id' => $transactionId,
        'integration_id' => (int) config('paymob.integration_id'),
        'is_3d_secure' => true,
        'is_auth' => false,
        'is_capture' => false,
        'is_refunded' => false,
        'is_standalone_payment' => true,
        'is_voided' => false,
        'order' => ['id' => $orderId],
        'owner' => 778899,
        'pending' => false,
        'source_data' => [
            'pan' => '2346',
            'sub_type' => 'MasterCard',
            'type' => 'card',
        ],
        'success' => true,
    ];

    return [
        'type' => 'TRANSACTION',
        'obj' => $object,
        'hmac' => paymobWebhookHmac($object),
    ];
}

function paymobWebhookHmac(array $object): string
{
    $concatenated = ''
        .$object['amount_cents']
        .$object['created_at']
        .$object['currency']
        .paymobBool($object['error_occured'])
        .paymobBool($object['has_parent_transaction'])
        .$object['id']
        .$object['integration_id']
        .paymobBool($object['is_3d_secure'])
        .paymobBool($object['is_auth'])
        .paymobBool($object['is_capture'])
        .paymobBool($object['is_refunded'])
        .paymobBool($object['is_standalone_payment'])
        .paymobBool($object['is_voided'])
        .$object['order']['id']
        .$object['owner']
        .paymobBool($object['pending'])
        .$object['source_data']['pan']
        .$object['source_data']['sub_type']
        .$object['source_data']['type']
        .paymobBool($object['success']);

    return hash_hmac('sha512', $concatenated, (string) config('paymob.hmac_secret'));
}

function paymobBool(bool $value): string
{
    return $value ? 'true' : 'false';
}

function assertPaymobStartRequestsWereSent(Booking $booking): void
{
    Http::assertSent(fn (Request $request): bool => $request->url() === paymobBaseUrl().'/api/auth/tokens'
        && $request['api_key'] === 'test-api-key');

    Http::assertSent(fn (Request $request): bool => $request->url() === paymobBaseUrl().'/api/ecommerce/orders'
        && $request['auth_token'] === 'auth-token'
        && $request['delivery_needed'] === false
        && $request['amount_cents'] === 25000
        && $request['currency'] === 'EGP'
        && str_starts_with((string) $request['merchant_order_id'], 'booking-'.$booking->id.'-')
        && $request['items'][0]['name'] === 'Meeting Room'
        && $request['items'][0]['amount_cents'] === 25000);

    Http::assertSent(fn (Request $request): bool => $request->url() === paymobBaseUrl().'/api/acceptance/payment_keys'
        && $request['auth_token'] === 'auth-token'
        && $request['amount_cents'] === 25000
        && $request['currency'] === 'EGP'
        && $request['order_id'] === 111222333
        && $request['integration_id'] === (int) config('paymob.integration_id')
        && $request['billing_data']['first_name'] === 'Yasser'
        && $request['billing_data']['last_name'] === 'Ali'
        && $request['billing_data']['email'] === 'yasser@example.com');
}

test('authenticated booking confirmation queues work and captures payment from a real Paymob webhook route', function (): void {
    fakeSuccessfulPaymobStartAndCapture();
    $processedJobs = [];

    Event::listen(JobProcessed::class, function (JobProcessed $event) use (&$processedJobs): void {
        $processedJobs[] = [
            'queue' => $event->job->getQueue(),
            'name' => $event->job->resolveName(),
        ];
    });

    [$customer, $resource, $slot] = paymobBookingActors();

    $createResponse = $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.store'), paymobBookingPayload($customer, $resource, $slot))
        ->assertCreated()
        ->assertJsonPath('booking.status', 'pending');

    $booking = Booking::query()->findOrFail($createResponse->json('booking.id'));

    $this->assertDatabaseHas('bookings', [
        'id' => $booking->id,
        'customer_id' => $customer->id,
        'resource_id' => $resource->id,
        'slot_id' => $slot->id,
        'status' => 'pending',
    ]);

    confirmBookingThroughHttp($customer, $booking)
        ->assertOk()
        ->assertJsonPath('booking.status', 'confirmed')
        ->assertJsonPath('payment.amount_cents', 25000)
        ->assertJsonPath('payment.paymob_order_id', 111222333)
        ->assertJsonPath('payment.payment_key', 'payment-token')
        ->assertJsonPath('payment.redirect_url', paymobBaseUrl().'/api/acceptance/iframes/456789?payment_token=payment-token');

    $booking->refresh();

    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
    $this->assertDatabaseHas('jobs', [
        'queue' => 'bookings',
    ]);

    $queuedConfirmation = DB::table('jobs')->where('queue', 'bookings')->first();
    expect($queuedConfirmation?->payload)->toContain('SendBookingConfirmation');

    assertPaymobStartRequestsWereSent($booking);

    $this->assertDatabaseHas('payments', [
        'paymob_reference' => '111222333',
        'order_type' => Booking::class,
        'order_id' => (string) $booking->id,
        'amount_cents' => 25000,
        'status' => 'processing',
    ]);

    runQueuedJob('bookings');

    expect(DB::table('jobs')->where('queue', 'bookings')->count())->toBe(0);
    expect($processedJobs)->toContain(
        ['queue' => 'bookings', 'name' => SendBookingConfirmation::class],
    );

    Queue::fake([ProcessPaymobPayment::class]);

    $this->postJson('/paymob/webhook', signedPaymobWebhookPayload())
        ->assertOk()
        ->assertJson(['message' => 'Webhook received.']);

    $this->assertDatabaseHas('paymob_webhook_events', [
        'transaction_id' => 987654321,
    ]);

    Queue::assertPushed(ProcessPaymobPayment::class, 1);

    processPaymobCapture($booking);

    Http::assertSent(fn (Request $request): bool => $request->url() === paymobBaseUrl().'/api/acceptance/capture?token=auth-token'
        && $request['transaction_id'] === 987654321
        && $request['amount_cents'] === 25000);

    $this->assertDatabaseHas('payments', [
        'paymob_reference' => '987654321',
        'transaction_id' => 987654321,
        'order_type' => Booking::class,
        'order_id' => (string) $booking->id,
        'amount_cents' => 25000,
        'status' => 'captured',
    ]);

    expect(Payment::query()->where('status', 'captured')->count())->toBe(1);
});

test('gateway timeout while starting payment leaves a failed payment attempt and no captured charge', function (): void {
    fakeTimedOutPaymobOrderCreation();
    [$customer, $resource, $slot] = paymobBookingActors();

    $createResponse = $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.store'), paymobBookingPayload($customer, $resource, $slot))
        ->assertCreated();

    $booking = Booking::query()->findOrFail($createResponse->json('booking.id'));

    confirmBookingThroughHttp($customer, $booking)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('paymob');

    $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'confirmed']);
    $this->assertDatabaseHas('payments', [
        'order_type' => Booking::class,
        'order_id' => (string) $booking->id,
        'amount_cents' => 25000,
        'status' => 'failed',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === paymobBaseUrl().'/api/ecommerce/orders'
        && $request['amount_cents'] === 25000);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === paymobBaseUrl().'/api/acceptance/payment_keys');

    expect(Payment::query()->where('status', 'captured')->count())->toBe(0);
});

test('invalid Paymob webhook signature is rejected without a capture', function (): void {
    fakeSuccessfulPaymobStartAndCapture();
    [$customer, $resource, $slot] = paymobBookingActors();

    $createResponse = $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.store'), paymobBookingPayload($customer, $resource, $slot))
        ->assertCreated();

    $booking = Booking::query()->findOrFail($createResponse->json('booking.id'));
    confirmBookingThroughHttp($customer, $booking)->assertOk();

    $payload = signedPaymobWebhookPayload();
    $payload['hmac'] = str_repeat('0', 128);

    $this->postJson('/paymob/webhook', $payload)->assertForbidden();

    expect(PaymobWebhookEvent::query()->count())->toBe(0)
        ->and(DB::table('jobs')->where('queue', 'default')->count())->toBe(0)
        ->and(Payment::query()->where('status', 'captured')->count())->toBe(0);

    Http::assertNotSent(fn (Request $request): bool => $request->url() === paymobBaseUrl().'/api/acceptance/capture?token=auth-token');
});

test('duplicate Paymob webhook delivery records one event and captures one charge', function (): void {
    fakeSuccessfulPaymobStartAndCapture();
    [$customer, $resource, $slot] = paymobBookingActors();

    $createResponse = $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.store'), paymobBookingPayload($customer, $resource, $slot))
        ->assertCreated();

    $booking = Booking::query()->findOrFail($createResponse->json('booking.id'));
    confirmBookingThroughHttp($customer, $booking)->assertOk();

    $payload = signedPaymobWebhookPayload();

    Queue::fake([ProcessPaymobPayment::class]);

    $this->postJson('/paymob/webhook', $payload)
        ->assertOk()
        ->assertJson(['message' => 'Webhook received.']);
    $this->postJson('/paymob/webhook', $payload)
        ->assertOk()
        ->assertJson(['message' => 'Webhook already processed.']);

    expect(PaymobWebhookEvent::query()->where('transaction_id', 987654321)->count())->toBe(1)
        ->and(DB::table('jobs')->where('queue', 'default')->count())->toBe(0);

    Queue::assertPushed(ProcessPaymobPayment::class, 1);

    processPaymobCapture($booking);

    expect(Payment::query()->where('status', 'captured')->count())->toBe(1);

    Http::assertSentCount(4);
});

test('competing bookings for one slot leave one confirmed booking, one rejection, and one charge', function (): void {
    fakeSuccessfulPaymobStartAndCapture();

    [$customer, $resource, $slot] = paymobBookingActors();
    $rejectedCustomer = Customer::factory()->create();

    $accepted = $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.store'), paymobBookingPayload($customer, $resource, $slot))
        ->assertCreated();

    $this->actingAs($rejectedCustomer, 'sanctum')
        ->postJson(route('bookings.store'), paymobBookingPayload($rejectedCustomer, $resource, $slot))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slot_id');

    $booking = Booking::query()->findOrFail($accepted->json('booking.id'));

    confirmBookingThroughHttp($customer, $booking)->assertOk();

    Queue::fake([ProcessPaymobPayment::class]);

    $this->postJson('/paymob/webhook', signedPaymobWebhookPayload())
        ->assertOk()
        ->assertJson(['message' => 'Webhook received.']);

    Queue::assertPushed(ProcessPaymobPayment::class, 1);

    processPaymobCapture($booking);

    expect(Booking::query()->where('slot_id', $slot->id)->count())->toBe(1)
        ->and(Booking::query()->where('slot_id', $slot->id)->where('status', 'confirmed')->count())->toBe(1)
        ->and(Payment::query()->where('status', 'captured')->count())->toBe(1);
});
