<?php

namespace App\Support;

use App\Exceptions\ApiConflictException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiErrorResponse
{
    public static function from(Throwable $exception, Request $request): JsonResponse
    {
        $requestId = self::requestId($request);
        [$status, $code, $message, $details] = self::map($exception);

        if ($status >= 500) {
            Log::error('Unhandled API exception', [
                'request_id' => $requestId,
                'exception_class' => $exception::class,
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
                'path' => $request->path(),
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);
        }

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'status' => $status,
                'request_id' => $requestId,
                'details' => $details,
            ],
        ], $status, array_merge(
            $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [],
            ['X-Request-ID' => $requestId],
        ));
    }

    private static function requestId(Request $request): string
    {
        $requestId = $request->attributes->get('request_id');

        if (! is_string($requestId)) {
            $requestId = (string) Str::uuid();
            $request->attributes->set('request_id', $requestId);
        }

        return $requestId;
    }

    /** @return array{int, string, string, array<string, mixed>} */
    private static function map(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof ValidationException => [
                422,
                'validation_failed',
                'The given data was invalid.',
                ['fields' => $exception->errors()],
            ],
            $exception instanceof AuthenticationException => [401, 'unauthenticated', 'Authentication is required.', []],
            $exception instanceof AuthorizationException => [403, 'forbidden', 'You are not allowed to perform this action.', []],
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => [404, 'not_found', 'The requested resource was not found.', []],
            $exception instanceof ApiConflictException => [409, 'conflict', $exception->publicMessage(), []],
            $exception instanceof ThrottleRequestsException => [429, 'too_many_requests', 'Too many requests. Please try again later.', []],
            $exception instanceof HttpExceptionInterface => self::mapHttpException($exception),
            default => [500, 'internal_server_error', 'An unexpected error occurred.', []],
        };
    }

    /** @return array{int, string, string, array<string, mixed>} */
    private static function mapHttpException(HttpExceptionInterface $exception): array
    {
        $status = $exception->getStatusCode();

        return match ($status) {
            401 => [401, 'unauthenticated', 'Authentication is required.', []],
            403 => [403, 'forbidden', 'You are not allowed to perform this action.', []],
            404 => [404, 'not_found', 'The requested resource was not found.', []],
            409 => [409, 'conflict', 'The request conflicts with the current state of the resource.', []],
            422 => [422, 'validation_failed', 'The given data was invalid.', []],
            429 => [429, 'too_many_requests', 'Too many requests. Please try again later.', []],
            default => $status >= 500
                ? [500, 'internal_server_error', 'An unexpected error occurred.', []]
                : [$status, 'http_error', 'The request could not be completed.', []],
        };
    }
}
