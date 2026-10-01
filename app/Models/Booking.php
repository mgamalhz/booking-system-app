<?php

namespace App\Models;

use App\Builders\BookingQueryBuilder;
use App\ValueObjects\SlotDuration;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Paymob\Laravel\Contracts\PaymobCapturable;
use Paymob\Laravel\DTO\CapturePaymentResponseDto;
use Paymob\Laravel\Models\Payment;

/**
 * @property int $customer_id
 * @property int $slot_id
 * @property-read Customer $customer
 * @property-read Slot $slot
 */
class Booking extends Model implements PaymobCapturable
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory , SoftDeletes;

    protected $fillable =
        [
            'customer_id',
            'resource_id',
            'slot_id',
            'status',
            'reminder_sent_at',
            'start_date',
            'end_date',
            'recurrence_rule',
            'max_participants',
            'type',
            'id',
        ];

    protected $with = ['slot', 'resource', 'customer'];

    protected $appends = ['duration'];

    protected $casts = [
        'status' => 'string',
        'reminder_sent_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<\App\Models\Resource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    /**
     * @return BelongsTo<Slot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    /**
     * @return HasMany<BookingDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(BookingDocument::class);
    }

    public function getDurationAttribute(): SlotDuration
    {
        return $this->slot->duration();
    }

    public function newEloquentBuilder($query): BookingQueryBuilder
    {
        return new BookingQueryBuilder($query);
    }

    public function isPaymobCaptured(): bool
    {
        return Payment::query()
            ->where('order_type', self::class)
            ->where('order_id', (string) $this->id)
            ->where('status', 'captured')
            ->exists();
    }

    public function markPaymobCaptured(CapturePaymentResponseDto $response): void
    {
        $this->forceFill([
            'status' => 'confirmed',
        ])->save();
    }
}
