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
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('offer_code')->unique();
            $table->foreignId('candidate_application_id')->constrained('candidate_applications')->restrictOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->decimal('offered_ctc', 12, 2)->nullable();
            $table->decimal('fixed_salary', 12, 2)->nullable();
            $table->decimal('variable_salary', 12, 2)->nullable();
            $table->decimal('joining_bonus', 12, 2)->nullable();
            $table->date('offer_date');
            $table->date('offer_expiry')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('accepted_at')->nullable();
            $table->date('expected_joining_date')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index('candidate_application_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
