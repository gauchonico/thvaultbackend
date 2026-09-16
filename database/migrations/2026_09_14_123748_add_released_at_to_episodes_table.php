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
            // When this episode actually aired/released — distinct from created_at (when it was
            // uploaded into the catalog). Left null for back-catalog uploads so bulk-importing an
            // ended series doesn't make every episode look "new". Only set for a genuinely new drop.
            $table->date('released_at')->nullable()->after('video_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn('released_at');
        });
    }
};
