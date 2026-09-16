<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Paymob\Laravel\Contracts\PaymobClientContract;
use Paymob\Laravel\DTO\AuthenticationResponseDto;
use Paymob\Laravel\DTO\CapturePaymentResponseDto;
use Paymob\Laravel\DTO\OrderResponseDto;
use Paymob\Laravel\DTO\PaymentKeyResponseDto;
use Paymob\Laravel\DTO\RegisterOrderData;
use Paymob\Laravel\DTO\RequestPaymentKeyData;

uses(RefreshDatabase::class);

function swapPaymobClientForBookingConfirmationTest(): void
{
    config()->set('paymob.integration_id', 123);
    config()->set('paymob.iframe_id', 456);

    app()->instance(PaymobClientContract::class, new class implements PaymobClientContract
    {
        public function authenticate(): AuthenticationResponseDto
        {
            throw new BadMethodCallException('Not used in this test.');
        }

        public function registerOrder(RegisterOrderData $data): OrderResponseDto
        {
            return new OrderResponseDto(id: 987654);
        }

        public function requestPaymentKey(RequestPaymentKeyData $data): PaymentKeyResponseDto
        {
            return new PaymentKeyResponseDto(token: 'payment-token');
        }

        public function paymentRedirectUrl(string $paymentToken, ?int $iframeId = null): string
        {
            return rtrim((string) config('paymob.base_url'), '/')
                .'/api/acceptance/iframes/'
                .(int) config('paymob.iframe_id')
                .'?payment_token='.urlencode($paymentToken);
        }

        public function capture(int $transactionId, int $amountCents): CapturePaymentResponseDto
        {
            throw new BadMethodCallException('Not used in this test.');
        }
    });
}

test('it runs the queued booking confirmation flow after a booking is confirmed', function () {
    DB::commit();

    try {
        config()->set('queue.default', 'sync');
        swapPaymobClientForBookingConfirmationTest();

        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson(route('bookings.update', $booking), ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('booking.status', 'confirmed')
            ->assertJsonPath('payment.payment_key', 'payment-token');

        expect($customer->notifications()->count())->toBe(1);
        expect($customer->notifications()->first()->data)->toMatchArray([
            'booking_id' => $booking->id,
            'message' => 'Your booking has been confirmed.',
        ]);

        $this->assertDatabaseHas('payments', [
            'order_type' => Booking::class,
            'order_id' => (string) $booking->id,
            'paymob_reference' => '987654',
            'status' => 'processing',
        ]);
    } finally {
        DB::table('payments')->delete();
        DB::table('notifications')->delete();
        DB::table('personal_access_tokens')->delete();
        DB::table('bookings')->delete();
        DB::table('slots')->delete();
        DB::table('resources')->delete();
        DB::table('customers')->delete();
        DB::beginTransaction();
    }
});

test('it creates api bookings through the service as pending and does not dispatch confirmation', function () {
    config()->set('cache.default', 'array');

    $customer = Customer::factory()->create();

    $response = $this->actingAs($customer, 'sanctum')->postJson(route('bookings.store'), [
        'customer_id' => Customer::factory()->create()->id,
        'resource_id' => Resource::factory()->create()->id,
        'slot_id' => Slot::factory()->create()->id,
        'status' => 'confirmed',
        'type' => 'one-on-one',
    ])->assertCreated();

    $booking = Booking::query()->findOrFail($response->json('booking.id'));

    expect($booking->status)->toBe('pending');
    expect($booking->customer_id)->toBe($customer->id);
    expect($customer->notifications()->count())->toBe(0);
});

test('it requires authentication to create a booking', function () {
    $this->postJson(route('bookings.store'), [
        'customer_id' => Customer::factory()->create()->id,
        'resource_id' => Resource::factory()->create()->id,
        'slot_id' => Slot::factory()->create()->id,
        'type' => 'one-on-one',
    ])->assertUnauthorized();
});

test('it rejects booking updates from another user', function () {
    $booking = Booking::factory()->create([
        'customer_id' => Customer::factory()->create()->id,
        'status' => 'pending',
    ]);

    $this->actingAs(Customer::factory()->create(), 'sanctum')
        ->postJson(route('bookings.update', $booking), [
            'status' => 'confirmed',
        ])
        ->assertForbidden();
});

test('it rejects api booking creation when the slot is already unavailable', function () {
    config()->set('cache.default', 'array');

    $customer = Customer::factory()->create();

    $slot = Slot::factory()->create();

    Booking::factory()->create([
        'slot_id' => $slot->id,
        'status' => 'confirmed',
        'type' => 'one-on-one',
    ]);

    $this->actingAs($customer, 'sanctum')->postJson(route('bookings.store'), [
        'customer_id' => Customer::factory()->create()->id,
        'resource_id' => Resource::factory()->create()->id,
        'slot_id' => $slot->id,
        'status' => 'confirmed',
        'type' => 'one-on-one',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slot_id');

    expect(Booking::query()->where('slot_id', $slot->id)->count())->toBe(1);
    expect($customer->notifications()->count())->toBe(0);
});
