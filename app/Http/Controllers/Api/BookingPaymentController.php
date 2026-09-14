<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\BookingPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingPaymentController extends Controller
{
    public function nonPaid(Request $request, BookingPaymentService $bookingPaymentService): JsonResponse
    {
        $customer = $request->user();
        abort_unless($customer instanceof Customer, 401);

        return response()->json([
            'success' => true,
            'bookings' => $bookingPaymentService->getPendingBookingsForCustomer((int) $customer->id),
        ]);
    }

    public function pay(Request $request, int $booking, BookingPaymentService $bookingPaymentService): JsonResponse
    {
        $customer = $request->user();
        abort_unless($customer instanceof Customer, 401);

        return response()->json([
            'success' => true,
            ...$bookingPaymentService->payPendingBooking($booking, (int) $customer->id),
        ]);
    }
}
