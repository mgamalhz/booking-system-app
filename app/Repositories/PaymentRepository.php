<?php

namespace App\Repositories;

use App\Models\Booking;
use App\Repositories\Interfaces\PaymentRepositoryInterface;
use Paymob\Laravel\Models\Payment;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function hasCapturedPaymentForBooking(Booking $booking): bool
    {
        return Payment::query()
            ->where('order_type', Booking::class)
            ->where('order_id', (string) $booking->id)
            ->where('status', 'captured')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveProcessingPayment(string $paymobReference, Booking $booking, int $amountCents, array $payload): Payment
    {
        /** @var Payment $payment */
        $payment = Payment::query()->updateOrCreate(
            [
                'paymob_reference' => $paymobReference,
            ],
            [
                'order_type' => Booking::class,
                'order_id' => (string) $booking->id,
                'amount_cents' => $amountCents,
                'status' => 'processing',
                'response_payload' => $payload,
            ],
        );

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveFailedPayment(string $paymobReference, Booking $booking, int $amountCents, array $payload): Payment
    {
        /** @var Payment $payment */
        $payment = Payment::query()->updateOrCreate(
            [
                'paymob_reference' => $paymobReference,
            ],
            [
                'order_type' => Booking::class,
                'order_id' => (string) $booking->id,
                'amount_cents' => $amountCents,
                'status' => 'failed',
                'response_payload' => $payload,
            ],
        );

        return $payment;
    }
}
