<?php

namespace App\Jobs;

use App\Services\BookingDocumentCleanupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CleanupBookingDocumentsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $retentionDays = 3) {}

    /**
     * Execute the job.
     */
    public function handle(BookingDocumentCleanupService $cleanupService): void
    {
        $cleanupService->cleanup($this->retentionDays);
    }
}
