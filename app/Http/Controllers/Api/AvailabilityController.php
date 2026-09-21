<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvailabilityRequest;
use App\Services\SlotAvailabilityService;
use Illuminate\Http\JsonResponse;

class AvailabilityController extends Controller
{
    public function __invoke(
        AvailabilityRequest $request,
        int $resource,
        SlotAvailabilityService $availability,
    ): JsonResponse {
        return response()->json([
            'data' => $availability->forResourceId(
                $resource,
                $request->string('start_date')->toString(),
                $request->string('end_date')->toString(),
                $request->string('timezone')->toString(),
                $request->filters(),
            ),
        ]);
    }
}
