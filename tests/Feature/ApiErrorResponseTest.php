<?php

use App\Exceptions\ApiConflictException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Route::prefix('api/error-contract-tests')->middleware('api')->group(function () {
        Route::get('validation', fn () => throw ValidationException::withMessages([
            'email' => ['The email field is required.'],
        ]));
        Route::get('authentication', fn () => throw new AuthenticationException('internal auth detail'));
        Route::get('authorization', fn () => throw new AuthorizationException('internal policy detail'));
        Route::get('missing-model', fn () => throw (new ModelNotFoundException)->setModel('Secret\\Internal\\Model', [123]));
        Route::get('conflict', fn () => throw new ApiConflictException('The slot has already been booked.'));
        Route::get('throttled', fn () => throw new ThrottleRequestsException('internal throttle detail', null, ['Retry-After' => '10']));
        Route::get('server-error', fn () => throw new RuntimeException('SQL password=very-secret C:\\private\\path'));
    });
});

test('validation errors use the stable envelope and preserve field details', function () {
    $this->getJson('/api/error-contract-tests/validation')
        ->assertUnprocessable()
        ->assertJson([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The given data was invalid.',
                'status' => 422,
                'details' => ['fields' => ['email' => ['The email field is required.']]],
            ],
        ])
        ->assertJsonStructure(['error' => ['code', 'message', 'status', 'request_id', 'details']]);
});

test('authentication errors are safe JSON', function () {
    $this->getJson('/api/error-contract-tests/authentication')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonMissing(['internal auth detail']);
});

test('authorization errors are safe JSON', function () {
    $this->getJson('/api/error-contract-tests/authorization')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonMissing(['internal policy detail']);
});

test('missing resources return JSON even when no API route matched', function () {
    $this->get('/api/route-that-does-not-exist', ['Accept' => 'text/html'])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('error.code', 'not_found');
});

test('missing models do not expose model implementation details', function () {
    $this->getJson('/api/error-contract-tests/missing-model')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonMissing(['Secret\\Internal\\Model']);
});

test('conflicts use status 409 and a client safe message', function () {
    $this->getJson('/api/error-contract-tests/conflict')
        ->assertConflict()
        ->assertJsonPath('error.code', 'conflict')
        ->assertJsonPath('error.message', 'The slot has already been booked.');
});

test('throttling preserves retry headers without exposing internal messages', function () {
    $this->getJson('/api/error-contract-tests/throttled')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '10')
        ->assertJsonPath('error.code', 'too_many_requests')
        ->assertJsonMissing(['internal throttle detail']);
});

test('unexpected failures are correlated logged safely and never leak internals', function () {
    Log::spy();

    $response = $this->withHeader('X-Request-ID', 'client-request-123')
        ->get('/api/error-contract-tests/server-error', ['Accept' => 'text/html'])
        ->assertInternalServerError()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('X-Request-ID', 'client-request-123')
        ->assertJsonPath('error.code', 'internal_server_error')
        ->assertJsonPath('error.request_id', 'client-request-123');

    expect($response->getContent())
        ->not->toContain('very-secret')
        ->not->toContain('private')
        ->not->toContain('RuntimeException');

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'Unhandled API exception'
        && $context['request_id'] === 'client-request-123'
        && $context['exception_class'] === RuntimeException::class
        && ! array_key_exists('payload', $context)
    )->once();
});
