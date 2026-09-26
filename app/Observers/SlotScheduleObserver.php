<?php

namespace App\Observers;

use App\Models\Slot;
use App\Services\AvailabilityCacheInvalidator;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class SlotScheduleObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Slot $slot): void
    {
        app(AvailabilityCacheInvalidator::class)->schedule('slot_created');
    }

    public function updated(Slot $slot): void
    {
        if ($slot->wasChanged(['date', 'start_time', 'end_time', 'status'])) {
            app(AvailabilityCacheInvalidator::class)->schedule('slot_updated');
        }
    }

    public function deleted(Slot $slot): void
    {
        app(AvailabilityCacheInvalidator::class)->schedule('slot_deleted');
    }
}
