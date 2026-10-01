<?php

use Paymob\Laravel\PayMobWebHockController;

$paymobWebhookUrl = config('paymob.paymob_webhook_url');

if (is_string($paymobWebhookUrl) && $paymobWebhookUrl !== '') {
    Route::post($paymobWebhookUrl, [PayMobWebHockController::class, 'run']);
}
