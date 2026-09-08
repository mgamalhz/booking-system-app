<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Services\BookingPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Paymob\Laravel\Contracts\PaymobClientContract;
use Paymob\Laravel\DTO\AuthenticationResponseDto;
use Paymob\Laravel\DTO\CapturePaymentResponseDto;
use Paymob\Laravel\DTO\OrderResponseDto;
use Paymob\Laravel\DTO\PaymentKeyResponseDto;
use Paymob\Laravel\DTO\RegisterOrderData;
use Paymob\Laravel\DTO\RequestPaymentKeyData;
use Paymob\Laravel\Models\Payment;

uses(RefreshDatabase::class);

test('it lists authenticated customer non paid bookings', function () {
    $customer = Customer::factory()->create();
    $otherCustomer = Customer::factory()->create();
    $pendingBooking = Booking::factory()->for($customer)->create(['status' => 'pending']);
    Booking::factory()->for($customer)->create(['status' => 'confirmed']);
    Booking::factory()->for($customer)->create(['status' => 'canceled']);
    Booking::factory()->for($otherCustomer)->create(['status' => 'pending']);

    Payment::query()->create([
        'paymob_reference' => '12345',
        'order_type' => Booking::class,
        'order_id' => '999',
        'amount_cents' => 10000,
        'status' => 'captured',
    ]);

    $this->actingAs($customer, 'sanctum')
        ->getJson(route('bookings.non-paid'))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'bookings')
        ->assertJsonPath('bookings.0.id', $pendingBooking->id);
});

test('it creates a paymob payment key for a booking using the resource price', function () {
    config()->set('paymob.integration_id', 123);
    config()->set('paymob.iframe_id', 456);

    $customer = Customer::factory()->create([
        'name' => 'Yasser Ali',
        'email' => 'yasser@example.com',
        'phone' => '01012345678',
    ]);
    $resource = Resource::factory()->create(['name' => 'Meeting Room', 'price' => 250]);
    $booking = Booking::factory()->for($customer)->for($resource)->create(['status' => 'pending']);

    $fakePaymob = new class implements PaymobClientContract
    {
        public ?RegisterOrderData $registeredOrder = null;

        public ?RequestPaymentKeyData $requestedPaymentKey = null;

        public function authenticate(): AuthenticationResponseDto
        {
            throw new BadMethodCallException('Not used in this test.');
        }

        public function registerOrder(RegisterOrderData $data): OrderResponseDto
        {
            $this->registeredOrder = $data;

            return new OrderResponseDto(id: 987654);
        }

        public function requestPaymentKey(RequestPaymentKeyData $data): PaymentKeyResponseDto
        {
            $this->requestedPaymentKey = $data;

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
    };

    $this->app->instance(PaymobClientContract::class, $fakePaymob);

    expect(app(BookingPaymentService::class))->toBeInstanceOf(BookingPaymentService::class);

    $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.pay', $booking))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('booking_id', $booking->id)
        ->assertJsonPath('amount_cents', 25000)
        ->assertJsonPath('currency', 'EGP')
        ->assertJsonPath('paymob_order_id', 987654)
        ->assertJsonPath('payment_key', 'payment-token')
        ->assertJsonPath('redirect_url', rtrim((string) config('paymob.base_url'), '/').'/api/acceptance/iframes/456?payment_token=payment-token')
        ->assertJsonPath('message', 'Redirect customer to Paymob to complete payment.');

    expect($fakePaymob->registeredOrder?->amount)->toBe(25000)
        ->and($fakePaymob->requestedPaymentKey?->amountCents)->toBe(25000);

    $this->assertDatabaseHas('payments', [
        'paymob_reference' => '987654',
        'order_type' => Booking::class,
        'order_id' => (string) $booking->id,
        'amount_cents' => 25000,
        'status' => 'processing',
    ]);
});

test('it starts payment when a booking is confirmed', function () {
    config()->set('paymob.integration_id', 123);
    config()->set('paymob.iframe_id', 456);

    $customer = Customer::factory()->create([
        'name' => 'Yasser Ali',
        'email' => 'yasser@example.com',
        'phone' => '01012345678',
    ]);
    $resource = Resource::factory()->create(['name' => 'Meeting Room', 'price' => 250]);
    $booking = Booking::factory()->for($customer)->for($resource)->create(['status' => 'pending']);

    $fakePaymob = new class implements PaymobClientContract
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
    };

    $this->app->instance(PaymobClientContract::class, $fakePaymob);

    $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.update', $booking), ['status' => 'confirmed'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('booking.status', 'confirmed')
        ->assertJsonPath('payment.payment_key', 'payment-token')
        ->assertJsonPath('payment.paymob_order_id', 987654)
        ->assertJsonPath('payment.redirect_url', rtrim((string) config('paymob.base_url'), '/').'/api/acceptance/iframes/456?payment_token=payment-token');

    $this->assertDatabaseHas('payments', [
        'paymob_reference' => '987654',
        'order_type' => Booking::class,
        'order_id' => (string) $booking->id,
        'amount_cents' => 25000,
        'status' => 'processing',
    ]);
});

test('it records a failed payment and returns a clean error when paymob cannot start payment', function () {
    config()->set('paymob.integration_id', 123);
    config()->set('paymob.iframe_id', 456);

    $customer = Customer::factory()->create([
        'name' => 'Yasser Ali',
        'email' => 'yasser@example.com',
        'phone' => '01012345678',
    ]);
    $resource = Resource::factory()->create(['name' => 'Meeting Room', 'price' => 250]);
    $booking = Booking::factory()->for($customer)->for($resource)->create(['status' => 'pending']);

    $fakePaymob = new class implements PaymobClientContract
    {
        public function authenticate(): AuthenticationResponseDto
        {
            throw new BadMethodCallException('Not used in this test.');
        }

        public function registerOrder(RegisterOrderData $data): OrderResponseDto
        {
            throw new RuntimeException('Gateway unavailable.');
        }

        public function requestPaymentKey(RequestPaymentKeyData $data): PaymentKeyResponseDto
        {
            throw new BadMethodCallException('Not used in this test.');
        }

        public function paymentRedirectUrl(string $paymentToken, ?int $iframeId = null): string
        {
            throw new BadMethodCallException('Not used in this test.');
        }

        public function capture(int $transactionId, int $amountCents): CapturePaymentResponseDto
        {
            throw new BadMethodCallException('Not used in this test.');
        }
    };

    $this->app->instance(PaymobClientContract::class, $fakePaymob);

    $this->actingAs($customer, 'sanctum')
        ->postJson(route('bookings.update', $booking), ['status' => 'confirmed'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('paymob');

    $this->assertDatabaseHas('bookings', [
        'id' => $booking->id,
        'status' => 'confirmed',
    ]);

    $this->assertDatabaseHas('payments', [
        'order_type' => Booking::class,
        'order_id' => (string) $booking->id,
        'amount_cents' => 25000,
        'status' => 'failed',
    ]);
});
