<?php

namespace App\Repositories;

use App\Models\BookingDocument;
use App\Repositories\Interfaces\BookingDocumentRepositoryInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class BookingDocumentRepository implements BookingDocumentRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): BookingDocument
    {
        /** @var BookingDocument $document */
        $document = BookingDocument::query()->create($data);

        return $document;
    }

    /**
     * @return LazyCollection<int, BookingDocument>
     */
    public function expiredDeletedDocuments(string $disk, Carbon $cutoff): LazyCollection
    {
        return BookingDocument::onlyTrashed()
            ->where('disk', $disk)
            ->where('deleted_at', '<=', $cutoff)
            ->select(['id', 'key'])
            ->lazyById();
    }

    /**
     * @param  array<int, string>  $keys
     * @return Collection<int, string>
     */
    public function orphanedKeys(string $disk, array $keys): Collection
    {
        $existingKeys = BookingDocument::withTrashed()
            ->where('disk', $disk)
            ->whereIn('key', $keys)
            ->pluck('key');

        return collect($keys)
            ->diff($existingKeys)
            ->values();
    }

    /**
     * @param  array<int, int>  $ids
     */
    public function forceDeleteTrashedByIds(array $ids): void
    {
        BookingDocument::onlyTrashed()
            ->whereKey($ids)
            ->forceDelete();
    }
}
