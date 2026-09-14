<?php

namespace App\Models;

use Database\Factories\BookingDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookingDocument extends Model
{
    /** @use HasFactory<BookingDocumentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'booking_id',
        'disk',
        'key',
        'original_name',
        'mime',
        'size',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
