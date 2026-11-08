<?php

namespace App\Jobs;

use App\Exceptions\ApiConflictException;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Paymob\Laravel\Contracts\PaymobClientContract;
use Paymob\Laravel\Models\Payment;
use RuntimeException;

class CaptureBookingPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public int $paymentId, public int $transactionId)
    {
        $this->onQueue('bookings');
    }

    public function handle(PaymobClientContract $paymob): void
    {
        $payment = Payment::query()->findOrFail($this->paymentId);
        Cache::lock('booking:'.$payment->order_id.':payment', 150)->block(2, function () use ($payment, $paymob): void {
            $payment->refresh();
            if ($payment->status === 'captured') {
                return;
            }
            $booking = Booking::query()->findOrFail($payment->order_id);
            if ($booking->status !== 'pending' || $payment->status !== 'processing') {
                throw new ApiConflictException('This booking payment cannot be captured.');
            }
            // Network work is outside a database transaction; retries retain the same transaction ID.
            $response = $paymob->capture($this->transactionId, (int) $payment->amount_cents);
            if (($response->payload['success'] ?? false) !== true) {
                throw new RuntimeException('Paymob did not confirm capture.');
            }
            DB::transaction(function () use ($payment, $booking, $response): void {
                $booking->markPaymobCaptured($response);
                $payment->forceFill([
                    'status' => 'captured',
                    'transaction_id' => $this->transactionId,
                    'captured_at' => now(),
                    'response_payload' => $response->payload,
                ])->save();
            });
        });
    }
}
