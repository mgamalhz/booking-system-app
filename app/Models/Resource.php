<?php

namespace App\Models;

use Database\Factories\ResourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    /** @use HasFactory<ResourceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'capacity',
        'price',
        'status',
        'image',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasMany<Slot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(Slot::class);
    }
}
