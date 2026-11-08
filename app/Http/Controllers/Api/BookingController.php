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
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Paymob\Laravel\Models\Payment;

class BookingController extends Controller
{
    public function index(Request $request, BookingService $bookingService): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 50), 1), 100);

        if ($request->boolean('profile_bottleneck') && app()->environment(['local', 'testing'])) {
            return response()->json($this->bottleneckIndexPayload(
                $bookingService->getBottleneckBookingIndex($perPage, (int) $request->user()->id)
            ));
        }

        return response()->json($this->indexPayload($bookingService->getBookingIndex($perPage, (int) $request->user()->id)));
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        abort_unless((int) $booking->customer_id === (int) $request->user()->id, 403);

        return response()->json(['success' => true, 'booking' => $booking]);
    }

    public function store(StoreBookingRequest $request, BookingService $bookingService): JsonResponse
    {
        try {
            $booking = DB::transaction(function () use ($request, $bookingService) {
                $booking = $bookingService->createBookingForCustomer($request->validated(), (int) auth()->id());

                return $booking->fresh();
            });
        } catch (LockTimeoutException) {
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

        $data = $request->safe()->only(['status']);

        if (($data['status'] ?? null) === 'confirmed' && $booking->status === 'pending') {
            $payment = $bookingPaymentService->startPaymentForBooking($booking, (int) auth()->id());

            return response()->json([
                'success' => true,
                'booking' => $booking->fresh(),
                'payment' => $payment,
                'message' => 'Booking payment started successfully',
            ]);
        }

        $booking = Cache::lock('booking:'.$booking->id.':payment', 150)->block(2,
            function () use ($booking, $data, $bookingService) {
                if (Payment::query()->where('order_type', Booking::class)
                    ->where('order_id', (string) $booking->id)->where('status', 'processing')->exists()) {
                    throw new ApiConflictException('A payment is processing. Reconcile it before cancellation.');
                }

                return $bookingService->updateExistingBooking($booking, $data);
            }
        );

        $response = [
            'success' => true,
            'booking' => $booking,
            'message' => 'Booking updated successfully',
        ];

        return response()->json($response);
    }

    /**
     * @param  LengthAwarePaginator<int, Booking>  $bookings
     * @return array<string, mixed>
     */
    private function indexPayload(LengthAwarePaginator $bookings): array
    {
        $data = [];
        foreach ($bookings->items() as $booking) {
            $data[] = [
                'id' => $booking->id,
                'status' => $booking->status,
                'type' => $booking->type,
                'documents_count' => $booking->documents_count,
                'customer' => [
                    'id' => $booking->customer->id,
                    'name' => $booking->customer->name,
                    'email' => $booking->customer->email,
                ],
                'resource' => [
                    'id' => $booking->resource->id,
                    'name' => $booking->resource->name,
                    'type' => $booking->resource->type,
                ],
                'slot' => [
                    'id' => $booking->slot->id,
                    'date' => $booking->slot->date->toDateString(),
                    'start_time' => $booking->slot->start_time,
                    'end_time' => $booking->slot->end_time,
                ],
            ];
        }

        return [
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
            ],
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, Booking>  $bookings
     * @return array<string, mixed>
     */
    private function bottleneckIndexPayload(LengthAwarePaginator $bookings): array
    {
        $data = [];
        foreach ($bookings->items() as $booking) {
            $customer = $booking->customer()->first();
            $resource = $booking->resource()->first();
            $slot = $booking->slot()->first();

            $data[] = [
                'id' => $booking->id,
                'status' => $booking->status,
                'type' => $booking->type,
                'documents_count' => $booking->documents()->count(),
                'has_documents' => $booking->documents()->exists(),
                'customer' => [
                    'id' => $customer?->id,
                    'name' => $customer?->name,
                    'email' => $customer?->email,
                ],
                'resource' => [
                    'id' => $resource?->id,
                    'name' => $resource?->name,
                    'type' => $resource?->type,
                ],
                'slot' => [
                    'id' => $slot?->id,
                    'date' => $slot?->date?->toDateString(),
                    'start_time' => $slot?->start_time,
                    'end_time' => $slot?->end_time,
                ],
            ];
        }

        return [
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
            ],
        ];
    }
}
