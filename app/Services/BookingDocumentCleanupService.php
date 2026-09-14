<?php

namespace App\Services;

use App\Repositories\Interfaces\BookingDocumentRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;

class BookingDocumentCleanupService
{
    private const DISK = 'documents';

    private const PREFIX = 'bookings';

    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly BookingDocumentRepositoryInterface $documents,
    ) {}

    public function cleanup(int $retentionDays): void
    {
        $expiredSoftDeletedDocumentsDeleted = $this->deleteExpiredSoftDeletedDocuments($retentionDays);
        $orphanedDocumentObjectsDeleted = $this->deleteOrphanedDocumentObjects();

        Log::info('S3 booking document cleanup completed.', [
            'retention_days' => $retentionDays,
            'orphan_cleanup_grace_minutes' => $this->orphanCleanupGraceMinutes(),
            'expired_soft_deleted_documents_deleted' => $expiredSoftDeletedDocumentsDeleted,
            'orphaned_document_objects_deleted' => $orphanedDocumentObjectsDeleted,
            'total_document_objects_deleted' => $expiredSoftDeletedDocumentsDeleted + $orphanedDocumentObjectsDeleted,
        ]);
    }

    private function deleteExpiredSoftDeletedDocuments(int $retentionDays): int
    {
        return $this->documents
            ->expiredDeletedDocuments(
                self::DISK,
                now()->subDays($retentionDays),
            )
            ->chunk(self::BATCH_SIZE)
            ->reduce(
                fn (int $total, LazyCollection $documents): int => $total + $this->deleteDocumentBatch($documents),
                0,
            );
    }

    private function deleteDocumentBatch(LazyCollection $documents): int
    {
        $documents = $documents->collect();
        $keys = $documents->pluck('key')->all();

        Storage::disk(self::DISK)->delete($keys);

        $this->documents->forceDeleteTrashedByIds(
            $documents->pluck('id')->all(),
        );

        return count($keys);
    }

    private function deleteOrphanedDocumentObjects(): int
    {
        return collect(Storage::disk(self::DISK)->allFiles(self::PREFIX))
            ->filter(fn (string $key): bool => $this->isOutsideOrphanCleanupGraceWindow($key))
            ->chunk(self::BATCH_SIZE)
            ->reduce(
                fn (int $total, Collection $keys): int => $total + $this->deleteOrphanedBatch($keys),
                0,
            );
    }

    private function deleteOrphanedBatch(Collection $keys): int
    {
        $orphanedKeys = $this->documents->orphanedKeys(
            self::DISK,
            $keys->all(),
        );

        if ($orphanedKeys->isEmpty()) {
            return 0;
        }

        Storage::disk(self::DISK)->delete(
            $orphanedKeys->all(),
        );

        return $orphanedKeys->count();
    }

    private function isOutsideOrphanCleanupGraceWindow(string $key): bool
    {
        $cutoffTimestamp = now()
            ->subMinutes($this->orphanCleanupGraceMinutes())
            ->getTimestamp();

        return Storage::disk(self::DISK)->lastModified($key) <= $cutoffTimestamp;
    }

    private function orphanCleanupGraceMinutes(): int
    {
        return max(0, (int) config('filesystems.disks.documents.orphan_cleanup_grace_minutes', 15));
    }
}
