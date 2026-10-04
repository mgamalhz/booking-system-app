<?php

namespace App\Repositories\Interfaces;

use App\Models\Booking;
use Paymob\Laravel\Models\Payment;

interface PaymentRepositoryInterface
{
    public function hasCapturedPaymentForBooking(Booking $booking): bool;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveProcessingPayment(string $paymobReference, Booking $booking, int $amountCents, array $payload): Payment;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveFailedPayment(string $paymobReference, Booking $booking, int $amountCents, array $payload): Payment;
}
