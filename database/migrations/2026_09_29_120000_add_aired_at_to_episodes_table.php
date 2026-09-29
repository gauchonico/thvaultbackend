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
        Schema::table('episodes', function (Blueprint $table) {
            // When the episode originally aired on TV — drives the month/year filter on the
            // show page. Kept separate from released_at, which only flags a genuinely new drop
            // for the "New Episodes" badge and is left null for back-catalog uploads.
            $table->date('aired_at')->nullable()->after('released_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn('aired_at');
        });
    }
};
