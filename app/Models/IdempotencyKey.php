<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'customer_id',
        'idempotency_key',
        'payload_hash',
        'response_status',
        'response_body',
    ];
}
