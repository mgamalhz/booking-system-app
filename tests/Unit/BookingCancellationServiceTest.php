<?php

use App\Events\BookingCancelled;
use App\Models\Booking;
use App\Models\CancellationFeeSetting;
use App\Models\Slot;
use App\Repositories\Interfaces\BookingCancellationRepositoryInterface;
use App\Services\BookingCancellationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Event::fake([BookingCancelled::class]);
});

function cancellationBooking(string $status, string $date, string $startTime): Booking
{
    $booking = new Booking([
        'id' => 10,
        'customer_id' => 30,
        'resource_id' => 40,
        'status' => $status,
        'slot_id' => 20,
    ]);

    $booking->setRelation('slot', new Slot([
        'id' => 20,
        'date' => $date,
        'start_time' => $startTime,
        'end_time' => '14:00:00',
        'status' => 'active',
    ]));

    return $booking;
}

function cancellationSettings(): array
{
    return [
        new CancellationFeeSetting([
            'is_active' => true,
            'min_hours_before_slot' => 24,
            'max_hours_before_slot' => null,
            'fee_type' => 'fixed',
            'fee_amount' => 0,
        ]),
        new CancellationFeeSetting([
            'is_active' => true,
            'min_hours_before_slot' => 2,
            'max_hours_before_slot' => 24,
            'fee_type' => 'fixed',
            'fee_amount' => 50,
        ]),
        new CancellationFeeSetting([
            'is_active' => true,
            'min_hours_before_slot' => 0,
            'max_hours_before_slot' => 2,
            'fee_type' => 'fixed',
            'fee_amount' => 100,
        ]),
    ];
}

function cancellationFeeRule(
    int $minHours,
    ?int $maxHours,
    float $amount,
    string $type = 'fixed',
    bool $active = true,
): CancellationFeeSetting {
    return new CancellationFeeSetting([
        'is_active' => $active,
        'min_hours_before_slot' => $minHours,
        'max_hours_before_slot' => $maxHours,
        'fee_type' => $type,
        'fee_amount' => $amount,
    ]);
}

it('cancels a future booking and returns the matching cancellation fee', function () {
    $repository = new InMemoryBookingCancellationRepository(
        cancellationBooking('confirmed', '2026-07-10', '13:00:00'),
    );

    $service = new BookingCancellationService($repository);

    $result = $service->cancel(
        10,
        cancellationSettings(),
        Carbon::parse('2026-07-10 03:00:00'),
    );

    expect($result->booking->status)->toBe('canceled')
        ->and($result->fee)->toBe(50.0)
        ->and($repository->cancelCalls)->toBe(1);
});

it('returns zero fee when cancellation is inside the free period', function () {
    $repository = new InMemoryBookingCancellationRepository(
        cancellationBooking('confirmed', '2026-07-10', '13:00:00'),
    );

    $service = new BookingCancellationService($repository);

    $result = $service->cancel(
        10,
        cancellationSettings(),
        Carbon::parse('2026-07-09 13:00:00'),
    );

    expect($result->fee)->toBe(0.0);
});

it('uses the lower period when cancellation is exactly on an upper boundary', function () {
    $repository = new InMemoryBookingCancellationRepository(
        cancellationBooking('confirmed', '2026-07-10', '13:00:00'),
    );

    $service = new BookingCancellationService($repository);

    $result = $service->cancel(
        10,
        cancellationSettings(),
        Carbon::parse('2026-07-10 11:00:00'),
    );

    expect($result->fee)->toBe(50.0);
});

it('calculates percentage fees from the matched period', function () {
    $repository = new InMemoryBookingCancellationRepository(
        cancellationBooking('confirmed', '2026-07-10', '13:00:00'),
    );

    $service = new BookingCancellationService($repository);

    $result = $service->cancel(
        10,
        [
            cancellationFeeRule(0, 24, 25, 'percentage'),
        ],
        Carbon::parse('2026-07-10 03:00:00'),
        200,
    );

    expect($result->fee)->toBe(50.0);
});

it('rejects cancellation for a past slot', function () {
    $repository = new InMemoryBookingCancellationRepository(
        cancellationBooking('confirmed', '2026-07-10', '13:00:00'),
    );

    $service = new BookingCancellationService($repository);

    expect(fn () => $service->cancel(
        10,
        cancellationSettings(),
        Carbon::parse('2026-07-10 13:01:00'),
    ))->toThrow(Exception::class, 'Cannot cancel a past booking.')
        ->and($repository->cancelCalls)->toBe(0);
});

it('rejects cancellation when the booking is already canceled', function () {
    $repository = new InMemoryBookingCancellationRepository(
        cancellationBooking('canceled', '2026-07-10', '13:00:00'),
    );

    $service = new BookingCancellationService($repository);

    expect(fn () => $service->cancel(
        10,
        cancellationSettings(),
        Carbon::parse('2026-07-10 03:00:00'),
    ))->toThrow(Exception::class, 'Booking is already canceled.')
        ->and($repository->cancelCalls)->toBe(0);
});

final class InMemoryBookingCancellationRepository implements BookingCancellationRepositoryInterface
{
    public int $cancelCalls = 0;

    public function __construct(
        private Booking $booking,
    ) {}

    public function findForCancellation(int $bookingId): Booking
    {
        return $this->booking;
    }

    public function cancel(Booking $booking): Booking
    {
        $this->cancelCalls++;
        $booking->status = 'canceled';

        return $booking;
    }
}
