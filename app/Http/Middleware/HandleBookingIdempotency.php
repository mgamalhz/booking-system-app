<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class HandleBookingIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = trim((string) $request->header('Idempotency-Key'));

        if ($header === '') {
            return response()->json([
                'message' => 'The Idempotency-Key header is required.',
            ], 400);
        }

        try {
            return DB::transaction(function () use ($request, $next, $header) {
                $payloadHash = $this->hashPayload($request->all());
                $idempotencyKey = IdempotencyKey::query()
                    ->where('customer_id', (int) $request->user()->id)
                    ->where('idempotency_key', $header)
                    ->lockForUpdate()
                    ->first();

                if ($idempotencyKey !== null) {
                    if (! hash_equals($idempotencyKey->payload_hash, $payloadHash)) {
                        return response()->json([
                            'message' => 'The Idempotency-Key header was already used with a different payload.',
                        ], 422);
                    }

                    return response((string) $idempotencyKey->response_body, (int) $idempotencyKey->response_status)
                        ->header('Content-Type', 'application/json');
                }

                $idempotencyKey = IdempotencyKey::query()->create([
                    'customer_id' => (int) $request->user()->id,
                    'idempotency_key' => $header,
                    'payload_hash' => $payloadHash,
                ]);

                /** @var Response $response */
                $response = $next($request);

                if ($response->getStatusCode() >= 400) {
                    throw new HttpResponseException($response);
                }

                $idempotencyKey->update([
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                ]);

                return $response;
            });
        } catch (LockTimeoutException) {
            return response()->json([
                'success' => false,
                'message' => 'This slot is currently being booked. Please try again shortly.',
            ], 409);
        } catch (HttpResponseException $exception) {
            return $exception->getResponse();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($this->sortPayload($payload), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sortPayload(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortPayload($value);
            }
        }

        return $payload;
    }
}
