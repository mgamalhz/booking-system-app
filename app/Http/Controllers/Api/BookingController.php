<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Models\Booking;
use App\Services\BookingPaymentService;
use App\Services\BookingService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function store(StoreBookingRequest $request, BookingService $bookingService): JsonResponse
    {
        try {
            $booking = DB::transaction(function () use ($request, $bookingService) {
                $booking = $bookingService->createBookingForCustomer($request->validated(), (int) auth()->id());

                return $booking->fresh();
            });
        } catch (LockTimeoutException $exception) {
            throw new ApiConflictException('This slot is currently being booked. Please try again shortly.');
        }

        return response()->json([
            'success' => true,
            'booking' => $booking,
            'message' => 'Booking created successfully',
        ], 201);
    }

    /**
     * @throws \Throwable
     */
    public function update(
        UpdateBookingRequest $request,
        Booking $booking,
        BookingService $bookingService,
        BookingPaymentService $bookingPaymentService,
    ): JsonResponse {
        abort_if((int) $booking->customer_id !== (int) auth()->id(), 403);

        $validated = $request->validated();
        $shouldStartPayment = ($validated['status'] ?? null) === 'confirmed';

        if ($shouldStartPayment) {
            $validated['status'] = 'pending';
        }

        $booking = DB::transaction(function () use ($validated, $booking, $bookingService) {
            $booking = $bookingService->updateExistingBooking($booking, $validated);

            return $booking->fresh();
        });

        $response = [
            'success' => true,
            'booking' => $booking,
            'message' => 'Booking updated successfully',
        ];

        if ($shouldStartPayment) {
            $response['payment'] = $bookingPaymentService->startPaymentForBooking($booking, (int) auth()->id());
        }

        return response()->json($response);
    }
}
