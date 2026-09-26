<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.2: the minimal, audited separation record — the only reliable source for attrition.
 * One per employee, structured reason, optional notes. Not an HR separation module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_separations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('separation_date');
            $table->string('separation_reason', 30);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['separation_date'], 'employee_separations_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_separations');
    }
};
