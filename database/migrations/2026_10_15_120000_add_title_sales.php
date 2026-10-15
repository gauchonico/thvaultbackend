<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A show with either price set is "for sale": rent and/or buy per title,
        // open to everyone (no subscription needed). Both null = normal catalogue.
        Schema::table('shows', function (Blueprint $table) {
            $table->unsignedInteger('rent_price_ugx')->nullable()->after('channel_id');
            $table->unsignedInteger('buy_price_ugx')->nullable()->after('rent_price_ugx');
        });

        // Payments now pay for either a plan or a title.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->change();
            $table->foreignId('show_id')->nullable()->after('plan_id')->constrained()->nullOnDelete();
            $table->enum('purchase_type', ['rent', 'buy'])->nullable()->after('show_id');
        });

        // What a user can watch: buy = forever, rent = until expires_at.
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('show_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['rent', 'buy']);
            $table->unsignedInteger('price_ugx');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'show_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('show_id');
            $table->dropColumn('purchase_type');
        });
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn(['rent_price_ugx', 'buy_price_ugx']);
        });
    }
};
