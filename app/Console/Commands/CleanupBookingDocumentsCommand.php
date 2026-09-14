<?php

namespace App\Console\Commands;

use App\Jobs\CleanupBookingDocumentsJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('s3:cleanup-documents {--retention-days=3 : Soft-deleted document retention window in days}')]
#[Description('Queue cleanup for deletable booking document objects')]
class CleanupBookingDocumentsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionDays = max(0, (int) $this->option('retention-days'));

        CleanupBookingDocumentsJob::dispatch($retentionDays);

        $this->info("Queued S3 booking document cleanup with {$retentionDays} retention days.");

        return self::SUCCESS;
    }
}
