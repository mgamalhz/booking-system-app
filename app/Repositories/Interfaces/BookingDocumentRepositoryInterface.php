<?php

namespace App\Repositories\Interfaces;

use App\Models\BookingDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

interface BookingDocumentRepositoryInterface
{
    public function create(array $data): BookingDocument;

    /**
     * @return LazyCollection<int, BookingDocument>
     */
    public function expiredDeletedDocuments(string $disk, Carbon $cutoff): LazyCollection;

    /**
     * @param  array<int, string>  $keys
     * @return Collection<int, string>
     */
    public function orphanedKeys(string $disk, array $keys): Collection;

    /**
     * @param  array<int, int>  $ids
     */
    public function forceDeleteTrashedByIds(array $ids): void;
}
