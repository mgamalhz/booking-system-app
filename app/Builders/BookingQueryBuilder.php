<?php

namespace App\Builders;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends Builder<Booking>
 */
class BookingQueryBuilder extends Builder
{
    public function confirmed(): self
    {
        return $this->where('status', 'confirmed');
    }

    public function upcoming(): self
    {
        return $this->whereHas('slot', function ($query) {
            return $query->where('date', '>=', now());
        });
    }

    public function byCustomer(int $customerId): self
    {
        return $this->where('customer_id', $customerId);
    }

    public function bySlot(int $slotId): self
    {
        return $this->where('slot_id', $slotId);
    }

    public function withRelations(): self
    {
        return $this->with([
            'customer',
            'slot',
            'resource',
        ]);
    }

    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, mixed $total = null): LengthAwarePaginator|AbstractPaginator
    {
        return parent::paginate($perPage, $columns, $pageName, $page, $total)->appends(request()->query());
    }
}
