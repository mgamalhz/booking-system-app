<?php

namespace App\Models;

use App\Support\CustomerName;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $email
 * @property string $name
 */
#[Hidden(['password', 'given_name', 'family_name'])]
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            // Legacy name remains authoritative throughout the mixed-version window.
            // Query-builder/import writes bypass events and are repaired by the sweep.
            if (array_key_exists('name', $customer->getAttributes())) {
                $parts = CustomerName::split($customer->name);
                foreach ($parts as $key => $value) {
                    $customer->setAttribute($key, $value);
                }
            }
        });
    }

    public function billingName(): array
    {
        $parts = CustomerName::split($this->name);
        if ($this->getRawOriginal('given_name') === $parts['given_name']
            && $this->getRawOriginal('family_name') === $parts['family_name']) {
            return ['given_name' => $this->given_name, 'family_name' => $this->family_name];
        }

        Log::info('customer_name.legacy_fallback', ['customer_id' => $this->getKey()]);

        return $parts;
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'address',
        'city',
        'state',
        'zip',
        'country',
    ];

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasManyThrough<BookingDocument, Booking, $this>
     */
    public function bookingDocuments(): HasManyThrough
    {
        return $this->hasManyThrough(BookingDocument::class, Booking::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
