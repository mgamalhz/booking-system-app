<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function index(Request $request, BookingService $bookingService): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 50), 1), 100);

        if ($request->boolean('profile_bottleneck') && app()->environment(['local', 'testing'])) {
            return response()->json($this->bottleneckIndexPayload(
                $bookingService->getBottleneckBookingIndex($perPage)
            ));
        }

        return response()->json($this->indexPayload($bookingService->getBookingIndex($perPage)));
    }

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
    public function update(UpdateBookingRequest $request, Booking $booking, BookingService $bookingService): JsonResponse
    {
        abort_if((int) $booking->customer_id !== (int) auth()->id(), 403);

        $booking = DB::transaction(function () use ($request, $booking, $bookingService) {
            return $bookingService->updateExistingBooking($booking, $request->validated());
        });

        return response()->json([
            'success' => true,
            'booking' => $booking,
            'message' => 'Booking updated successfully',
        ]);
    }

    private function indexPayload(LengthAwarePaginator $bookings): array
    {
        return [
            'success' => true,
            'data' => $bookings->getCollection()->map(fn (Booking $booking): array => [
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
                    'date' => $booking->slot->date?->toDateString(),
                    'start_time' => $booking->slot->start_time,
                    'end_time' => $booking->slot->end_time,
                ],
            ]),
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
            ],
        ];
    }

    private function bottleneckIndexPayload(LengthAwarePaginator $bookings): array
    {
        return [
            'success' => true,
            'data' => $bookings->getCollection()->map(fn (Booking $booking): array => [
                'id' => $booking->id,
                'status' => $booking->status,
                'type' => $booking->type,
                'documents_count' => $booking->documents()->count(),
                'has_documents' => $booking->documents()->exists(),
                'customer' => [
                    'id' => $booking->customer()->first()?->id,
                    'name' => $booking->customer()->first()?->name,
                    'email' => $booking->customer()->first()?->email,
                ],
                'resource' => [
                    'id' => $booking->resource()->first()?->id,
                    'name' => $booking->resource()->first()?->name,
                    'type' => $booking->resource()->first()?->type,
                ],
                'slot' => [
                    'id' => $booking->slot()->first()?->id,
                    'date' => $booking->slot()->first()?->date?->toDateString(),
                    'start_time' => $booking->slot()->first()?->start_time,
                    'end_time' => $booking->slot()->first()?->end_time,
                ],
            ]),
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
            ],
        ];
    }
}
