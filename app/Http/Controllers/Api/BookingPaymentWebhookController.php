<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiConflictException;
use App\Jobs\CaptureBookingPayment;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Paymob\Laravel\Models\Payment;
use Paymob\Laravel\Models\PaymobWebhookEvent;
use Paymob\Laravel\PaymobClient;
use Paymob\Laravel\PayMobWebHockController;

class BookingPaymentWebhookController extends PayMobWebHockController
{
    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'hmac' => ['required', 'string'],
            'obj' => ['required', 'array'],
            'obj.id' => ['required', 'integer', 'min:1'],
            'obj.amount_cents' => ['required', 'integer', 'min:1'],
            'obj.order.id' => ['required', 'integer', 'min:1'],
            'obj.success' => ['required', 'boolean'],
            'obj.currency' => ['required', 'in:EGP'],
            'obj.integration_id' => ['required', 'integer'],
        ]);
        abort_unless(PaymobClient::checkHmac($request->all()), 403);

        abort_unless((int) data_get($data, 'obj.integration_id') === (int) config('paymob.integration_id'), 403);

        $payment = Payment::query()->where('paymob_reference', (string) data_get($data, 'obj.order.id'))
            ->where('order_type', Booking::class)->firstOrFail();
        if ((int) $payment->amount_cents !== (int) data_get($data, 'obj.amount_cents')) {
            throw new ApiConflictException('Payment amount does not match the booking payment.');
        }
        PaymobWebhookEvent::query()->firstOrCreate(
            ['transaction_id' => (int) data_get($data, 'obj.id')],
            ['payload' => ['obj' => $data['obj']]],
        );

        if ((bool) data_get($data, 'obj.success') && ! data_get($request->all(), 'obj.pending')
            && ! data_get($request->all(), 'obj.is_refunded') && ! data_get($request->all(), 'obj.is_voided')) {
            // Redeliver even if previously recorded: recording is not proof of queue delivery.
            CaptureBookingPayment::dispatch($payment->id, (int) data_get($data, 'obj.id'))->afterCommit();
        }

        return response()->json(['success' => true, 'message' => 'Webhook received.']);
    }
}
