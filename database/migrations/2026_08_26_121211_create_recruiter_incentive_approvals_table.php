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
        Schema::create('recruiter_incentive_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruiter_incentive_calculation_id');
            $table->foreign('recruiter_incentive_calculation_id', 'incentive_approvals_calc_foreign')
                ->references('id')->on('recruiter_incentive_calculations')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('changed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('recruiter_incentive_calculation_id', 'incentive_approvals_calc_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruiter_incentive_approvals');
    }
};
