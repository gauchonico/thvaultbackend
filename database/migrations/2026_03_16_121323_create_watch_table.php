<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tracks progress per episode (continue watching)
        Schema::create('watch_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('show_id')->constrained()->onDelete('cascade');
            $table->foreignId('episode_id')->constrained()->onDelete('cascade');
            $table->integer('progress')->default(0); // 0-100 percentage
            $table->boolean('completed')->default(false);
            $table->timestamp('watched_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'episode_id']);
        });

        // Watch later list
        Schema::create('watchlist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('show_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'show_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist');
        Schema::dropIfExists('watch_history');
    }
};