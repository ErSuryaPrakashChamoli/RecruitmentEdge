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
        Schema::create('recruiter_incentive_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruiter_incentive_calculation_id');
            $table->foreign('recruiter_incentive_calculation_id', 'incentive_adjustments_calc_foreign')
                ->references('id')->on('recruiter_incentive_calculations')->cascadeOnDelete();
            $table->string('adjustment_type');
            $table->decimal('amount_delta', 12, 2);
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('recruiter_incentive_calculation_id', 'incentive_adjustments_calc_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruiter_incentive_adjustments');
    }
};
