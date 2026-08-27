<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ReturnsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\BookingDocument;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class BookingDocumentTemporaryUrlController extends Controller
{
    use ReturnsApiResponses;

    public function __invoke(BookingDocument $booking_document): JsonResponse
    {
        $bookingDocument = $booking_document;

        $bookingDocument->loadMissing('booking.customer');

        $user = request()->user();

        abort_unless(
            $user instanceof Customer
                && (int) $bookingDocument->booking->customer_id === (int) $user->getAuthIdentifier(),
            403
        );

        $expiresInMinutes = (int) config('filesystems.disks.documents.temporary_url_expiration_minutes', 5);

        $url = Storage::disk('documents')->temporaryUrl(
            $bookingDocument->key,
            now()->addMinutes($expiresInMinutes)
        );

        return $this->successResponse([
            'url' => $url,
        ]);
    }
}
