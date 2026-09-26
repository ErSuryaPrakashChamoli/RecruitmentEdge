<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Employee referrals (Phase 4). Always points at an existing Candidate Master record — a
     * referral never duplicates a candidate. The bonus itself lives in the existing incentive engine
     * (recruiter_incentive_calculations, trigger `referral_joining`); this row only links to it.
     */
    public function up(): void
    {
        Schema::create('employee_referrals', function (Blueprint $table) {
            $table->id();
            $table->string('referral_code')->unique();
            $table->foreignId('referrer_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->restrictOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained('recruitment_requisitions')->nullOnDelete();
            $table->foreignId('candidate_application_id')->nullable()->constrained('candidate_applications')->nullOnDelete();
            $table->string('relationship', 40);
            $table->date('referred_at');
            $table->foreignId('source_id')->nullable()->constrained('candidate_sources')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('submitted');
            $table->foreignId('rejection_reason_id')->nullable()->constrained('recruitment_rejection_reasons')->nullOnDelete();
            $table->text('rejection_remarks')->nullable();
            $table->date('joining_date')->nullable();
            $table->boolean('incentive_eligible')->default(true);
            $table->string('incentive_status', 30)->default('eligible');
            $table->foreignId('incentive_calculation_id')->nullable();
            $table->foreign('incentive_calculation_id', 'employee_referrals_incentive_calc_foreign')->references('id')->on('recruiter_incentive_calculations')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['referrer_id', 'status']);
            $table->index(['candidate_id', 'requisition_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_referrals');
    }
};
