<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('paymob_reference')->unique();
            $table->unsignedBigInteger('transaction_id')->nullable()->index();
            $table->string('order_type')->nullable();
            $table->string('order_id')->nullable();
            $table->unsignedInteger('amount_cents');
            $table->string('status')->default('processing')->index();
            $table->json('response_payload')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index(['order_type', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
