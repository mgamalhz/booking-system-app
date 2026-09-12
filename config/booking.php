<?php

return [
    'lock' => [
        'wait_seconds' => env('BOOKING_LOCK_WAIT_SECONDS', 1),
        'ttl_seconds' => env('BOOKING_LOCK_TTL_SECONDS', 5),
    ],
    'availability_cache' => [
        'ttl_seconds' => env('BOOKING_AVAILABILITY_CACHE_TTL_SECONDS', 120),
        'lock_seconds' => env('BOOKING_AVAILABILITY_CACHE_LOCK_SECONDS', 10),
        'lock_wait_seconds' => env('BOOKING_AVAILABILITY_CACHE_LOCK_WAIT_SECONDS', 2),
        'version' => env('BOOKING_AVAILABILITY_CACHE_VERSION', 'v1'),
    ],
];
