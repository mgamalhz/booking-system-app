<?php

namespace App\Http\Controllers;

use App\Http\Requests\UploadRequest;
use App\Models\Booking;
use App\Services\Contracts\FilesUploadServiceInterface;
use Illuminate\Http\JsonResponse;

class S3UploadController extends Controller
{
    public function upload(UploadRequest $request, Booking $booking, FilesUploadServiceInterface $filesUploadService): JsonResponse
    {
        abort_if((int) $booking->customer_id !== (int) auth()->id(), 403);

        return response()->json([
            'success' => true,
            'document' => $filesUploadService->uploadBookingDocument($booking, $request->file('attachment')),
        ], 201);
    }
}
