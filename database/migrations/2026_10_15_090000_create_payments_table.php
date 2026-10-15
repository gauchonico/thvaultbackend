<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per payment attempt — successful or not — so Finance can reconcile
        // and support can answer "I paid but nothing happened".
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();          // ours, sent to the provider
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount_ugx');
            // mobile_money: PIN prompt sent to `phone`. card: Visa/Mastercard on the
            // provider's hosted checkout page (we never see card details).
            $table->enum('method', ['mobile_money', 'card'])->default('mobile_money');
            $table->string('phone', 20)->nullable();        // 2567XXXXXXXX, mobile money only
            $table->string('network', 20)->nullable();      // mtn | airtel | null when unknown
            $table->string('provider', 30);                 // fake | pesapal | ...
            $table->string('provider_reference')->nullable()->index();
            $table->enum('status', ['pending', 'successful', 'failed', 'expired'])->default('pending');
            $table->string('failure_reason')->nullable();
            $table->json('provider_payload')->nullable();   // last raw status from the provider
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
