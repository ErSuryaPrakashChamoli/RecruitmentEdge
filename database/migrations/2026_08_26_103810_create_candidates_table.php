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
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->string('candidate_code')->unique();
            $table->string('full_name');
            $table->string('mobile');
            $table->string('alternate_mobile')->nullable();
            $table->string('email')->nullable();
            $table->string('location')->nullable();
            $table->string('current_city')->nullable();
            $table->string('qualification')->nullable();
            $table->decimal('total_experience', 4, 1)->nullable();
            $table->decimal('relevant_experience', 4, 1)->nullable();
            $table->string('current_company')->nullable();
            $table->string('current_designation')->nullable();
            $table->decimal('current_salary', 12, 2)->nullable();
            $table->decimal('expected_salary', 12, 2)->nullable();
            $table->unsignedInteger('notice_period_days')->nullable();
            $table->json('skills')->nullable();
            $table->string('resume_path')->nullable();
            $table->foreignId('source_id')->constrained('candidate_sources')->restrictOnDelete();
            $table->string('source_details')->nullable();
            $table->foreignId('referral_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('mobile');
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
