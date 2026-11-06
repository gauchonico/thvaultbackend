<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clubs, creators and studios whose content The Vault carries. Each gets a hub
        // page fans can follow, and a share of revenue in proportion to watch time.
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', ['club', 'creator', 'studio'])->default('club');
            $table->string('sport')->nullable();            // clubs: Football, Rugby, Netball…
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->string('banner')->nullable();
            $table->string('accent_color', 9)->default('#1d4ed8');
            $table->decimal('revenue_share_percent', 5, 2)->default(0);
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('shows', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('channel_id')->constrained()->nullOnDelete();
        });

        Schema::create('partner_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['partner_id', 'user_id']);
        });

        // One row per playback session (an episode played, a live channel listened to,
        // an ePaper edition read), updated by heartbeats while it plays. A session
        // counts as a view once it passes analytics.view_seconds of real playback.
        Schema::create('plays', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Anonymous browser id, so signed-out viewers count as people too.
            $table->string('device_id', 64);
            $table->string('content_type', 20);              // episode | channel | epaper
            $table->unsignedBigInteger('content_id');
            // Copied from the episode at the start, so reports survive edits to the show.
            $table->unsignedBigInteger('show_id')->nullable();
            $table->unsignedBigInteger('partner_id')->nullable();
            // A paying subscriber at the time: their watch time divides subscription revenue.
            $table->boolean('is_subscriber')->default(false);
            $table->unsignedInteger('seconds')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');

            $table->index('started_at');
            $table->index(['content_type', 'content_id', 'started_at']);
            $table->index(['show_id', 'started_at']);
            $table->index(['partner_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plays');
        Schema::dropIfExists('partner_follows');
        Schema::table('shows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });
        Schema::dropIfExists('partners');
    }
};
