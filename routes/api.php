<?php

use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BookingDocumentTemporaryUrlController;
use App\Http\Controllers\Api\BookingPaymentController;
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
    Route::get('resources/{resource}/availability', AvailabilityController::class)->name('resources.availability');
    Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
    Route::get('bookings/non-paid', [BookingPaymentController::class, 'nonPaid'])->name('bookings.non-paid');
    Route::post('bookings', [BookingController::class, 'store'])
        ->middleware(HandleBookingIdempotency::class)
        ->name('bookings.store');
    Route::post('bookings/{booking}/pay', [BookingPaymentController::class, 'pay'])->name('bookings.pay');
    Route::post('bookings/{booking}/update', [BookingController::class, 'update'])->name('bookings.update');
    Route::post('bookings/{booking}/documents', [S3UploadController::class, 'upload'])->name('bookings.upload');
    Route::get('bookings/booking-documents/{booking_document}/temporary-url', BookingDocumentTemporaryUrlController::class)
        ->name('bookings.booking-documents.temporary-url');
});
