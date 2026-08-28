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
        Schema::create('recruiter_incentive_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruiter_incentive_calculation_id');
            $table->foreign('recruiter_incentive_calculation_id', 'incentive_payments_calc_foreign')
                ->references('id')->on('recruiter_incentive_calculations')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('payment_reference')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('recruiter_incentive_calculation_id', 'incentive_payments_calc_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruiter_incentive_payments');
    }
};
