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
        Schema::create('recruiter_incentive_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incentive_rule_id')->constrained('recruitment_incentive_rules')->restrictOnDelete();
            $table->foreignId('incentive_slab_id')->nullable()->constrained('recruitment_incentive_slabs')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->restrictOnDelete();
            $table->foreignId('candidate_application_id');
            $table->foreign('candidate_application_id', 'incentive_calc_application_foreign')
                ->references('id')->on('candidate_applications')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('achievement', 6, 2)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('calculated');
            $table->date('retention_due_at')->nullable();
            $table->timestamp('calculated_at');
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['incentive_rule_id', 'candidate_application_id', 'period_start', 'period_end'], 'incentive_calc_rule_app_period_unique');
            $table->index('employee_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruiter_incentive_calculations');
    }
};
