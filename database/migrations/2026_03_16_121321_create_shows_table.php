<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shows', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->string('poster')->nullable();
            $table->string('backdrop')->nullable();
            $table->json('genre');
            $table->integer('year');
            $table->string('rating');
            $table->integer('seasons')->default(1);
            $table->boolean('is_live')->default(false);
            $table->boolean('is_new')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shows');
    }
};