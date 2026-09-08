<?php

namespace App\Services;

use App\Models\Booking;
use App\Repositories\Interfaces\BookingRepositoryInterface;
use App\Repositories\Interfaces\PaymentRepositoryInterface;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Paymob\Laravel\Contracts\PaymobClientContract;
use Paymob\Laravel\DTO\BillingDataDto;
use Paymob\Laravel\DTO\OrderItemDto;
use Paymob\Laravel\DTO\RegisterOrderData;
use Paymob\Laravel\DTO\RequestPaymentKeyData;
use Throwable;

class BookingPaymentService
{
    public function __construct(
        private readonly BookingRepositoryInterface $bookings,
        private readonly PaymentRepositoryInterface $payments,
        private readonly PaymobClientContract $paymob,
    ) {}

    /**
     * @return Collection<int, Booking>
     */
    public function getPendingBookingsForCustomer(int $customerId): Collection
    {
        return $this->bookings->getPendingBookingsForCustomer($customerId);
    }

    /**
     * @return array<string, mixed>
     */
    public function payPendingBooking(int $bookingId, int $customerId): array
    {
        $booking = $this->bookings->findPendingBookingForCustomer($bookingId, $customerId);

        return $this->startPaymentForBooking($booking, $customerId);
    }

    /**
     * @return array<string, mixed>
     */
    public function startPaymentForBooking(Booking $booking, int $customerId): array
    {
        if ($this->payments->hasCapturedPaymentForBooking($booking)) {
            throw ValidationException::withMessages([
                'booking' => ['Booking is already paid.'],
            ]);
        }

        $amountCents = $this->amountCents($booking);
        $billingData = $this->billingData($booking);
        $integrationId = (int) config('paymob.integration_id');
        $paymentReference = 'booking-'.$booking->id.'-'.Str::uuid();

        if ($integrationId <= 0) {
            $this->payments->saveFailedPayment(
                paymobReference: $paymentReference,
                booking: $booking,
                amountCents: $amountCents,
                payload: ['message' => 'Paymob integration id is not configured.'],
            );

            throw ValidationException::withMessages([
                'paymob' => ['Paymob integration id is not configured.'],
            ]);
        }

        try {
            $order = $this->paymob->registerOrder(new RegisterOrderData(
                amount: $amountCents,
                currency: 'EGP',
                paymentMethodIds: [],
                items: [
                    new OrderItemDto(
                        name: $booking->resource->name,
                        amount: $amountCents,
                    ),
                ],
                billingData: $billingData,
                specialReference: $paymentReference,
            ));

            $paymentKey = $this->paymob->requestPaymentKey(new RequestPaymentKeyData(
                amountCents: $amountCents,
                currency: 'EGP',
                orderId: $order->id,
                integrationId: $integrationId,
                billingData: $billingData,
            ));

            $redirectUrl = $this->paymob->paymentRedirectUrl($paymentKey->token);
        } catch (RequestException $exception) {
            $response = $exception->response;

            Log::error('Paymob HTTP request failed', [
                'booking_id' => $booking->id,
                'customer_id' => $customerId,
                'amount_cents' => $amountCents,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $this->payments->saveFailedPayment(
                paymobReference: $paymentReference,
                booking: $booking,
                amountCents: $amountCents,
                payload: [
                    'status' => $response->status(),
                    'body' => $response->json() ?? $response->body(),
                ],
            );

            throw ValidationException::withMessages([
                'paymob' => ['Paymob returned an error while creating the payment.'],
            ]);
        } catch (Throwable $exception) {
            Log::error('Local payment creation failed before Paymob completed', [
                'booking_id' => $booking->id,
                'customer_id' => $customerId,
                'amount_cents' => $amountCents,
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->payments->saveFailedPayment(
                paymobReference: $paymentReference,
                booking: $booking,
                amountCents: $amountCents,
                payload: [
                    'exception_class' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            throw ValidationException::withMessages([
                'paymob' => ['Payment could not be started.'],
            ]);
        }

        $this->payments->saveProcessingPayment(
            paymobReference: (string) $order->id,
            booking: $booking,
            amountCents: $amountCents,
            payload: [
                'payment_key' => $paymentKey->token,
                'paymob_order_id' => $order->id,
                'redirect_url' => $redirectUrl,
            ],
        );

        return [
            'booking_id' => $booking->id,
            'amount_cents' => $amountCents,
            'currency' => 'EGP',
            'paymob_order_id' => $order->id,
            'payment_key' => $paymentKey->token,
            'redirect_url' => $redirectUrl,
            'message' => 'Redirect customer to Paymob to complete payment.',
        ];
    }

    private function amountCents(Booking $booking): int
    {
        return (int) $booking->resource->price * 100;
    }

    private function billingData(Booking $booking): BillingDataDto
    {
        $customer = $booking->customer;
        $nameParts = preg_split('/\s+/', trim($customer->name), 2) ?: [];

        return new BillingDataDto(
            firstName: $nameParts[0] ?? 'Customer',
            lastName: $nameParts[1] ?? 'Customer',
            email: $customer->email,
            phoneNumber: $customer->phone ?: '01000000000',
            street: $customer->address ?: 'NA',
            building: 'NA',
            city: $customer->city ?: 'Cairo',
            country: $customer->country ?: 'EG',
            state: $customer->state,
            apartment: 'NA',
            floor: 'NA',
            postalCode: $customer->zip,
        );
    }
}
