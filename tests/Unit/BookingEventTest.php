<?php

namespace Tests\Unit;

use App\Jobs\SendBookingConfirmation;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Paymob\Laravel\Contracts\PaymobClientContract;
use Paymob\Laravel\DTO\AuthenticationResponseDto;
use Paymob\Laravel\DTO\CapturePaymentResponseDto;
use Paymob\Laravel\DTO\OrderResponseDto;
use Paymob\Laravel\DTO\PaymentKeyResponseDto;
use Paymob\Laravel\DTO\RegisterOrderData;
use Paymob\Laravel\DTO\RequestPaymentKeyData;
use Tests\TestCase;

class BookingEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_confirmation_job_is_pushed_when_booking_is_updated()
    {
        Queue::fake();
        $this->swapPaymobClient();

        $booking = Booking::factory()->create();
        $this->actingAs(Customer::query()->findOrFail($booking->customer_id), 'sanctum')
            ->post(route('bookings.update', $booking), ['status' => 'confirmed'])
            ->assertOk();

        Queue::assertPushed(SendBookingConfirmation::class, function (SendBookingConfirmation $job) use ($booking) {
            return $job->booking->is($booking)
                && $job->afterCommit === true;
        });

    }

    public function test_booking_confirmation_job_is_not_pushed_when_booking_is_not_confirmed()
    {
        Queue::fake();

        $booking = Booking::factory()->create();
        $this->actingAs(Customer::query()->findOrFail($booking->customer_id), 'sanctum')
            ->post(route('bookings.update', $booking), ['status' => 'pending']);

        Queue::assertNotPushed(SendBookingConfirmation::class);
    }

    public function test_booking_confirmation_job_is_marked_to_dispatch_after_commit(): void
    {
        Queue::fake();
        $this->swapPaymobClient();

        $booking = Booking::factory()->create([
            'status' => 'pending',
        ]);

        $this->actingAs(Customer::query()->findOrFail($booking->customer_id), 'sanctum')
            ->post(route('bookings.update', $booking), [
                'status' => 'confirmed',
            ]);

        Queue::assertPushed(SendBookingConfirmation::class, function (SendBookingConfirmation $job) {
            return $job->afterCommit === true;
        });
    }

    private function swapPaymobClient(): void
    {
        config()->set('paymob.integration_id', 123);
        config()->set('paymob.iframe_id', 456);

        $this->app->instance(PaymobClientContract::class, new class implements PaymobClientContract
        {
            public function authenticate(): AuthenticationResponseDto
            {
                throw new \BadMethodCallException('Not used in this test.');
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
                throw new \BadMethodCallException('Not used in this test.');
            }
        });
    }
}
