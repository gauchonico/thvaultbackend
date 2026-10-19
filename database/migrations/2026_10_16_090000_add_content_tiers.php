<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Free = any signed-in account. Subscribers = paid plans (Freemium gets a
        // monthly preview allowance). A rent/buy price overrides either.
        Schema::table('shows', function (Blueprint $table) {
            $table->enum('access_level', ['free', 'subscribers'])->default('free')->after('buy_price_ugx');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('is_free')->default(false)->after('price_ugx');
        });

        // The Freemium plan: free shows + a monthly preview of Premium. No payment.
        if (!DB::table('plans')->where('slug', 'free')->exists()) {
            DB::table('plans')->insert([
                'name'              => 'Freemium',
                'slug'              => 'free',
                'price_ugx'         => 0,
                'is_free'           => true,
                'resolution'        => 'SD (480p)',
                'max_screens'       => 1,
                'downloads_allowed' => false,
                'is_popular'        => false,
                'sort_order'        => 0,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }

        // Seconds of Premium previewed per user per period (month by default).
        Schema::create('preview_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period', 10); // "2026-10" (month), "2026-10-16" (day) or "all"
            $table->unsignedInteger('seconds_used')->default(0);
            $table->timestamp('last_beat_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_usages');
        DB::table('plans')->where('slug', 'free')->delete();
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('is_free'));
        Schema::table('shows', fn (Blueprint $table) => $table->dropColumn('access_level'));
    }
};
