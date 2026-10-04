<?php

namespace App\Models;

use App\ValueObjects\SlotDuration;
use Database\Factories\SlotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @method static findOrFail(mixed $slot_id)
 */
class Slot extends Model
{
    /** @use HasFactory<SlotFactory> */
    use HasFactory;

    protected $fillable = [
        'start_time',
        'end_time',
        'date',
        'status',
        'id',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function duration(): SlotDuration
    {
        return SlotDuration::fromTimes(
            Carbon::parse($this->start_time),
            Carbon::parse($this->end_time)
        );
    }
}
