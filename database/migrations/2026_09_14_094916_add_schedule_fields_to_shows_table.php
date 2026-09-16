<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            // Days of the week this show airs on regular/live TV, e.g. ["monday","tuesday","wednesday","thursday","friday"]
            $table->json('air_days')->nullable()->after('trailer_url');
            $table->time('air_start_time')->nullable()->after('air_days');
            $table->time('air_end_time')->nullable()->after('air_start_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn(['air_days', 'air_start_time', 'air_end_time']);
        });
    }
};
