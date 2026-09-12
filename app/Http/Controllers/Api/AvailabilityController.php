<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvailabilityRequest;
use App\Models\Resource;
use App\Services\SlotAvailabilityService;
use Illuminate\Http\JsonResponse;

class AvailabilityController extends Controller
{
    public function __invoke(
        AvailabilityRequest $request,
        Resource $resource,
        SlotAvailabilityService $availability,
    ): JsonResponse {
        abort_unless($resource->status === 'active', 404);

        return response()->json([
            'data' => $availability->forResource(
                $resource,
                $request->string('start_date')->toString(),
                $request->string('end_date')->toString(),
                $request->string('timezone')->toString(),
                $request->filters(),
            ),
        ]);
    }
}
