<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $resources = Resource::query()->where('status', 'active')->orderBy('id')
            ->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return response()->json(['success' => true, 'data' => $resources->items(), 'meta' => [
            'current_page' => $resources->currentPage(),
            'per_page' => $resources->perPage(),
            'total' => $resources->total(),
        ]]);
    }
}
