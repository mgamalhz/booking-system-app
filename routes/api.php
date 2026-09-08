<?php

use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BookingDocumentTemporaryUrlController;
use App\Http\Controllers\Api\BookingPaymentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\S3UploadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/test', function (Request $request) {
    return response()->json(['message' => 'test']);
});

Route::post('register', [AuthController::class, 'register'])->name('auth.register');
Route::post('login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('booking', [BookingController::class, 'store'])->name('bookings.store');
    Route::post('booking/{booking}/update', [BookingController::class, 'update'])->name('bookings.update');
    Route::get('booking/non-paid', [BookingPaymentController::class, 'nonPaid'])->name('bookings.non-paid');
    Route::post('booking/{booking}/pay', [BookingPaymentController::class, 'pay'])->name('bookings.pay');
    Route::post('booking/{booking}/documents', [S3UploadController::class, 'upload'])->name('bookings.upload');
    Route::get('booking-documents/{booking_document}/temporary-url', BookingDocumentTemporaryUrlController::class)
        ->name('booking-documents.temporary-url');
});
