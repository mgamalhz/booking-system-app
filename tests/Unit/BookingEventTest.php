<?php

namespace Tests\Unit;

use App\Events\BookingConfirmed;
use App\Jobs\SendBookingConfirmation;
use App\Listeners\BookingConfirmationNotificationListener;
use App\Listeners\LogConfirmedBooking;
use App\Models\Booking;
use App\Models\Customer;
use App\Notifications\BookingConfirmationNotification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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

        $booking = Booking::factory()->create([
            'status' => 'pending',
        ]);
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

        $booking = Booking::factory()->create([
            'status' => 'pending',
        ]);
        $this->actingAs(Customer::query()->findOrFail($booking->customer_id), 'sanctum')
            ->post(route('bookings.update', $booking), ['status' => 'pending']);

        Queue::assertNotPushed(SendBookingConfirmation::class);
    }

    public function test_booking_confirmation_job_is_marked_to_dispatch_after_commit(): void
    {
        Queue::fake();

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

    public function test_booking_confirmed_event_keeps_booking_and_broadcasts_on_private_channel(): void
    {
        $booking = Booking::factory()->create();
        $event = new BookingConfirmed($booking);

        $this->assertTrue($event->booking->is($booking));
        $this->assertCount(1, $event->broadcastOn());
        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
    }

    public function test_send_booking_confirmation_job_notifies_customer(): void
    {
        Notification::fake();
        $booking = Booking::factory()->create();

        (new SendBookingConfirmation($booking))->handle();

        Notification::assertSentTo(
            $booking->customer,
            BookingConfirmationNotification::class,
            fn (BookingConfirmationNotification $notification): bool => $notification->booking->is($booking)
        );
    }

    public function test_booking_confirmation_listener_notifies_customer(): void
    {
        Notification::fake();
        $booking = Booking::factory()->create();

        app(BookingConfirmationNotificationListener::class)->handle(new BookingConfirmed($booking));

        Notification::assertSentTo($booking->customer, BookingConfirmationNotification::class);
    }

    public function test_log_confirmed_booking_listener_writes_booking_id(): void
    {
        $booking = Booking::factory()->create();

        Log::shouldReceive('debug')
            ->once()
            ->with('Booking confirmed: '.$booking->id, []);

        app(LogConfirmedBooking::class)->handle(new BookingConfirmed($booking));
    }
}
