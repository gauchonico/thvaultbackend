<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A newspaper title (Daily Nation, Business Daily…). Each has many dated editions.
        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('country', 50)->default('Regional'); // Kenya, Uganda, Tanzania, Regional
            $table->string('language', 30)->default('English');
            $table->string('tagline')->nullable();
            $table->string('logo')->nullable();
            $table->string('accent_color', 7)->default('#c8102e'); // masthead colour in the reader
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // One printed issue: the PDF plus a cover image of its front page.
        Schema::create('epaper_editions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained()->cascadeOnDelete();
            $table->date('issue_date');
            $table->string('title')->nullable(); // "Saturday Magazine", "Weekend Edition"…
            $table->string('cover')->nullable();
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedSmallInteger('page_count')->default(0);
            $table->enum('access_level', ['free', 'subscribers'])->default('subscribers');
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['publication_id', 'issue_date']);
            $table->index('issue_date');
        });

        $now = now();
        DB::table('publications')->insert([
            ['name' => 'Daily Nation', 'slug' => 'daily-nation', 'country' => 'Kenya', 'language' => 'English',
             'tagline' => 'The truth wherever you go', 'accent_color' => '#c8102e', 'sort_order' => 1],
            ['name' => 'Business Daily', 'slug' => 'business-daily', 'country' => 'Kenya', 'language' => 'English',
             'tagline' => 'Business news, markets and policy', 'accent_color' => '#0b6e4f', 'sort_order' => 2],
            ['name' => 'The EastAfrican', 'slug' => 'the-eastafrican', 'country' => 'Regional', 'language' => 'English',
             'tagline' => 'The region, every week', 'accent_color' => '#1d4e89', 'sort_order' => 3],
        ]);
        DB::table('publications')->update(['created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::dropIfExists('epaper_editions');
        Schema::dropIfExists('publications');
    }
};
