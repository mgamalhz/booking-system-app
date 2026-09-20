<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->validRequestId($request->header('X-Request-ID')) ?? (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    private function validRequestId(?string $requestId): ?string
    {
        if ($requestId === null || ! preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $requestId)) {
            return null;
        }

        return $requestId;
    }
}
