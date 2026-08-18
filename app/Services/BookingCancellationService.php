<?php

namespace App\Services;

use App\Events\BookingCancelled;
use App\Exceptions\InvalidBookingStatusTransition;
use App\Models\Booking;
use App\Models\CancellationFeeSetting;
use App\Repositories\Interfaces\BookingCancellationRepositoryInterface;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Exception;
use InvalidArgumentException;

class BookingCancellationService
{
    public function __construct(
        private readonly BookingCancellationRepositoryInterface $bookings,
    ) {}

    /**
     * @param  iterable<CancellationFeeSetting>  $feeSettings
     *
     * @throws Exception
     */
    public function cancel(
        int $bookingId,
        iterable $feeSettings,
        ?CarbonInterface $cancelledAt = null,
        ?float $baseAmount = null,
    ): BookingCancellationResult {
        $cancelledAt ??= Carbon::now();

        $booking = $this->bookings->findForCancellation($bookingId);

        if ($booking->status === 'canceled') {
            throw new Exception('Booking is already canceled.');
        }

        if (! in_array($booking->status, ['pending', 'confirmed'], true)) {
            throw InvalidBookingStatusTransition::for($booking->id, (string) $booking->status, 'canceled');
        }

        $slotStartsAt = $this->slotStartsAt($booking);

        if ($cancelledAt->greaterThanOrEqualTo($slotStartsAt)) {
            throw new Exception('Cannot cancel a past booking.');
        }

        $fee = $this->calculateFee($feeSettings, $slotStartsAt, $cancelledAt, $baseAmount);

        $fromStatus = (string) $booking->status;
        $booking = $this->bookings->cancel($booking);

        BookingCancelled::dispatch(
            $booking->id,
            $booking->customer_id,
            $booking->slot_id,
            $booking->resource_id,
            $fromStatus,
            'canceled',
            $cancelledAt->toISOString(),
        );

        return new BookingCancellationResult($booking, $fee);
    }

    private function slotStartsAt(Booking $booking): CarbonInterface
    {
        $date = Carbon::parse($booking->slot->date)->toDateString();

        return Carbon::parse($date.' '.$booking->slot->start_time);
    }

    /**
     * @param  iterable<CancellationFeeSetting>  $settings
     */
    private function calculateFee(
        iterable $settings,
        CarbonInterface $slotStartsAt,
        CarbonInterface $cancelledAt,
        ?float $baseAmount = null,
    ): float {
        $hoursBeforeSlot = $cancelledAt->diffInMinutes($slotStartsAt) / 60;

        foreach ($settings as $setting) {
            if (! $setting->is_active || ! $this->matchesPeriod($setting, $hoursBeforeSlot)) {
                continue;
            }

            return $this->feeForSetting($setting, $baseAmount);
        }

        return 0.0;
    }

    private function matchesPeriod(CancellationFeeSetting $setting, float $hoursBeforeSlot): bool
    {
        $min = (int) $setting->min_hours_before_slot;
        $max = $setting->max_hours_before_slot === null
            ? null
            : (int) $setting->max_hours_before_slot;

        return $hoursBeforeSlot >= $min && ($max === null || $hoursBeforeSlot < $max);
    }

    private function feeForSetting(CancellationFeeSetting $setting, ?float $baseAmount): float
    {
        if ($setting->fee_type === 'fixed') {
            return (float) $setting->fee_amount;
        }

        if ($baseAmount === null) {
            throw new InvalidArgumentException('Base amount is required for percentage cancellation fees.');
        }

        return round($baseAmount * ((float) $setting->fee_amount / 100), 2);
    }
}
