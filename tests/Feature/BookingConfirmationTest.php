<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Resource;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Paymob\Laravel\Contracts\PaymobClientContract;
use Paymob\Laravel\DTO\AuthenticationResponseDto;
use Paymob\Laravel\DTO\CapturePaymentResponseDto;
use Paymob\Laravel\DTO\OrderResponseDto;
use Paymob\Laravel\DTO\PaymentKeyResponseDto;
use Paymob\Laravel\DTO\RegisterOrderData;
use Paymob\Laravel\DTO\RequestPaymentKeyData;
use Symfony\Component\Process\Process;

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
    $databasePath = database_path('booking-confirmation-'.uniqid().'.sqlite');

    touch($databasePath);

    try {
        config()->set('database.connections.sqlite.database', $databasePath);
        config()->set('database.default', 'sqlite');
        config()->set('telescope.storage.database.connection', 'sqlite');
        DB::setDefaultConnection('sqlite');
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);

        $customer = Customer::factory()->create();

        $booking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);

        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/ConfirmBookingRequest.php'),
            $databasePath,
            $customer->createToken('confirm-booking-test')->plainTextToken,
            (string) $booking->id,
        ], timeout: 30);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

        $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($response['status'])->toBe(200);
        expect($response['json']['payment']['payment_key'])->toBe('payment-token');

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
