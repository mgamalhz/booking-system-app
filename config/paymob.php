<?php

return [
    'api_key' => env('PAYMOB_API_KEY'),

    'integration_id' => env('PAYMOB_INTEGRATION_ID'),

    'iframe_id' => env('PAYMOB_IFRAME_ID'),

    'secret_key' => env('PAYMOB_SECRET_KEY'),

    'base_url' => env('PAYMOB_BASE_URL'),

    'timeout' => env('PAYMOB_TIMEOUT', 30),

    'connect_timeout' => env('PAYMOB_CONNECT_TIMEOUT', 10),

    'paymob_webhook_url' => env('PAYMOB_WEBHOOK_URL'),

    'hmac_secret' => env('PAYMOB_HMAC', ''),
];
