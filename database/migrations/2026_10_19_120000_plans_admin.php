<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Hidden plans leave the plan picker but keep their subscribers and history.
            $table->boolean('is_active')->default(true)->after('is_popular');
            // Extra bullet points for the plan card ("Ad-free", "Early access…").
            $table->json('features')->nullable()->after('is_active');
        });

        // Small key/value store for settings admins change from the console.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn(['is_active', 'features']));
    }
};
