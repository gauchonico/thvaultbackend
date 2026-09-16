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
            $table->string('type')->default('show')->after('id'); // 'show' | 'podcast'
            $table->foreignId('channel_id')->nullable()->after('type')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_id');
            $table->dropColumn('type');
        });
    }
};
