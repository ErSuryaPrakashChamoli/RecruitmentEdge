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
        Schema::create('ai_evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained('ai_evaluations')->cascadeOnDelete();
            $table->boolean('passed');
            $table->json('actual_output')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('run_at');
            $table->timestamps();

            $table->index('evaluation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_evaluation_runs');
    }
};
