<?php

use App\Listeners\SendFailedJobAlert;
use App\Models\Booking;
use App\Notifications\BookingConfirmationNotification;
use App\Notifications\BookingReminderNotification;
use App\Notifications\FailedQueueJobNotification;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('booking confirmation notification has a bounded retry policy', function () {
    $notification = new BookingConfirmationNotification(Booking::factory()->make());

    expect($notification->tries)->toBe(5)
        ->and($notification->backoff)->toBe([10, 30, 60, 120]);
});

test('booking confirmation notification defines channels mail content and database payload', function () {
    $booking = Booking::factory()->create();
    $notification = new BookingConfirmationNotification($booking);
    $mail = $notification->toMail($booking->customer);

    expect($notification->via($booking->customer))->toBe(['mail', 'database'])
        ->and($mail->introLines)->toContain('The introduction to the notification.')
        ->and($mail->actionText)->toBe('Notification Action')
        ->and($notification->toArray($booking->customer))->toMatchArray([
            'booking_id' => $booking->id,
            'message' => 'Your booking has been confirmed.',
            'action_url' => url('/bookings/'.$booking->id),
        ]);
});

test('booking reminder notification has a bounded retry policy', function () {
    $notification = new BookingReminderNotification(Booking::factory()->make());

    expect($notification->tries)->toBe(3)
        ->and($notification->backoff)->toBe([60, 300, 900]);
});

test('booking reminder notification defines channels and mail content', function () {
    $booking = Booking::factory()->create();
    $notification = new BookingReminderNotification($booking);
    $mail = $notification->toMail($booking->customer);

    expect($notification->via($booking->customer))->toBe(['mail'])
        ->and($mail->subject)->toBe('Booking Reminder')
        ->and($mail->greeting)->toBe('Hello '.$booking->customer->name)
        ->and($mail->introLines)->toContain('This is a reminder for your upcoming booking.')
        ->and($mail->actionText)->toBe('View Booking');
});

test('booking confirmation notification logs structured context when it fails permanently', function () {
    $booking = Booking::factory()->create();
    $exception = new RuntimeException('SMTP timeout');

    Log::shouldReceive('error')
        ->once()
        ->with('Booking confirmation notification failed permanently', Mockery::on(
            fn (array $context): bool => $context['job'] === BookingConfirmationNotification::class
                && $context['booking_id'] === $booking->id
                && $context['customer_id'] === $booking->customer_id
                && $context['customer_email'] === $booking->customer->email
                && $context['exception'] === RuntimeException::class
                && $context['message'] === 'SMTP timeout'
        ));

    (new BookingConfirmationNotification($booking))->failed($exception);
});

test('booking reminder notification logs structured context when it fails permanently', function () {
    $booking = Booking::factory()->create([
        'reminder_sent_at' => now(),
    ]);
    $exception = new RuntimeException('Mail transport rejected message');

    Log::shouldReceive('error')
        ->once()
        ->with('Booking reminder notification failed permanently', Mockery::on(
            fn (array $context): bool => $context['job'] === BookingReminderNotification::class
                && $context['booking_id'] === $booking->id
                && $context['customer_id'] === $booking->customer_id
                && $context['customer_email'] === $booking->customer->email
                && $context['slot_id'] === $booking->slot_id
                && $context['slot_date']->isSameDay($booking->slot->date)
                && $context['slot_start_time'] === $booking->slot->start_time
                && $context['exception'] === RuntimeException::class
                && $context['message'] === 'Mail transport rejected message'
        ));

    (new BookingReminderNotification($booking))->failed($exception);

    expect($booking->fresh()->reminder_sent_at)->toBeNull();
});

test('queued booking reminder failure makes the booking eligible for retry', function () {
    $booking = Booking::factory()->create([
        'reminder_sent_at' => now(),
    ]);
    $exception = new RuntimeException('Permanent SMTP failure');
    $notification = new BookingReminderNotification($booking);
    $queuedNotification = new SendQueuedNotifications(
        $booking->customer,
        $notification,
        $notification->via($booking->customer),
    );

    Log::shouldReceive('error')->once();

    $queuedNotification->failed($exception);

    expect($booking->fresh()->reminder_sent_at)->toBeNull();
});

test('failed job listener logs critical context and sends the configured alert', function () {
    config(['queue.failed_alerts.mail_to' => 'ops@example.com']);

    Notification::fake();

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('getQueue')->once()->andReturn('default');
    $job->shouldReceive('getJobId')->once()->andReturn('123');
    $job->shouldReceive('uuid')->once()->andReturn('failed-job-uuid');
    $job->shouldReceive('resolveName')->once()->andReturn(BookingConfirmationNotification::class);
    $job->shouldReceive('attempts')->once()->andReturn(5);

    Log::shouldReceive('critical')
        ->once()
        ->with('Queue job landed in failed_jobs', Mockery::on(
            fn (array $context): bool => $context['connection'] === 'database'
                && $context['queue'] === 'default'
                && $context['job_id'] === '123'
                && $context['uuid'] === 'failed-job-uuid'
                && $context['name'] === BookingConfirmationNotification::class
                && $context['attempts'] === 5
                && $context['exception'] === RuntimeException::class
                && $context['message'] === 'Final failure'
        ));

    app(SendFailedJobAlert::class)->handle(new JobFailed(
        'database',
        $job,
        new RuntimeException('Final failure'),
    ));

    Notification::assertSentOnDemand(
        FailedQueueJobNotification::class,
        fn (FailedQueueJobNotification $notification, array $channels, object $notifiable): bool => in_array('mail', $channels, true)
            && $notifiable->routeNotificationFor('mail') === 'ops@example.com'
    );
});

test('failed queue job notification renders scalar values and hides complex context', function () {
    $notification = new FailedQueueJobNotification([
        'name' => 'ExampleJob',
        'connection' => 'database',
        'queue' => 'default',
        'job_id' => 123,
        'uuid' => 'uuid-1',
        'attempts' => 3,
        'exception' => RuntimeException::class,
        'message' => ['not scalar'],
    ]);

    $mail = $notification->toMail(new stdClass);

    expect($notification->via(new stdClass))->toBe(['mail'])
        ->and($mail->subject)->toBe('Queue job failed permanently')
        ->and($mail->introLines)->toContain('Job: ExampleJob')
        ->and($mail->introLines)->toContain('Queue: database/default')
        ->and($mail->introLines)->toContain('Job ID: 123')
        ->and($mail->introLines)->toContain('Message: n/a');
});
