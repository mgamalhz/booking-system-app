<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BookingPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingPaymentController extends Controller
{
    public function nonPaid(Request $request, BookingPaymentService $bookingPaymentService): JsonResponse
    {
        return response()->json([
            'success' => true,
            'bookings' => $bookingPaymentService->getPendingBookingsForCustomer((int) $request->user()->id),
        ]);
    }

    public function pay(Request $request, int $booking, BookingPaymentService $bookingPaymentService): JsonResponse
    {
        return response()->json([
            'success' => true,
            ...$bookingPaymentService->payPendingBooking($booking, (int) $request->user()->id),
        ]);
    }
}
