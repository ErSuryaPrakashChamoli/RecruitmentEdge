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
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_application_id')->constrained('candidate_applications')->cascadeOnDelete();
            $table->unsignedInteger('round_number')->default(1);
            $table->string('round_name')->nullable();
            $table->foreignId('interviewer_id')->constrained('employees')->restrictOnDelete();
            $table->dateTime('scheduled_at');
            $table->string('mode');
            $table->string('location')->nullable();
            $table->string('status')->default('pending');
            $table->string('result')->nullable();
            $table->foreignId('rejection_reason_id')->nullable()->constrained('recruitment_rejection_reasons')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index('candidate_application_id');
            $table->index('interviewer_id');
            $table->index('status');
            $table->index('scheduled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interviews');
    }
};
