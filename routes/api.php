<?php

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BookingDocumentTemporaryUrlController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\S3UploadController;
use App\Http\Middleware\HandleBookingIdempotency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/test', function (Request $request) {
    return response()->json(['message' => 'test']);
});

Route::post('register', [AuthController::class, 'register'])->name('auth.register');
Route::post('login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::name('bookings.')->group(function () {
        Route::post('bookings/store', [BookingController::class, 'store'])
            ->middleware(HandleBookingIdempotency::class)
            ->name('store');
        Route::post('booking/{booking}/update', [BookingController::class, 'update'])->name('update');
        Route::post('booking/{booking}/documents', [S3UploadController::class, 'upload'])->name('upload');
        Route::get('booking-documents/{booking_document}/temporary-url', BookingDocumentTemporaryUrlController::class)
            ->name('booking-documents.temporary-url');
    });

});
