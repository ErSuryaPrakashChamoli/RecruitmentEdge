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
        Schema::create('ai_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category');
            $table->text('question');
            $table->string('expected_intent')->nullable();
            $table->string('expected_tool')->nullable();
            $table->string('expected_permission')->nullable();
            $table->json('context')->nullable();
            $table->json('assertions')->nullable();
            $table->timestamps();

            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_evaluations');
    }
};
