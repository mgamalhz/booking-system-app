<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingStatusEvent extends Model
{
    protected $fillable = [
        'booking_id',
        'customer_id',
        'slot_id',
        'resource_id',
        'from_status',
        'to_status',
        'event_type',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];
}
