<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            $booking = $bookingService->updateExistingBooking($booking, $request->validated());

            return $booking->fresh();
        });

        return response()->json([
            'success' => true,
            'booking' => $booking,
            'message' => 'Booking updated successfully',
        ]);
    }

    private function indexPayload(LengthAwarePaginator $bookings): array
    {
        $data = [];
        foreach ($bookings->items() as $booking) {
            if (! $booking instanceof Booking) {
                continue;
            }

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

    private function bottleneckIndexPayload(LengthAwarePaginator $bookings): array
    {
        $data = [];
        foreach ($bookings->items() as $booking) {
            if (! $booking instanceof Booking) {
                continue;
            }

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
