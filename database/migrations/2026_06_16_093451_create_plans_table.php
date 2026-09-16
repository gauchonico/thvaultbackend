<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique(); // matches the `id` used in the frontend's plans.ts
            $table->unsignedInteger('price_ugx');
            $table->string('resolution');
            $table->unsignedTinyInteger('max_screens')->default(1);
            $table->boolean('downloads_allowed')->default(true);
            $table->boolean('is_popular')->default(false);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};