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
        Schema::create('recruitment_manual_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruiter_id')->constrained('employees')->restrictOnDelete();
            $table->date('activity_date');
            $table->string('metric');
            $table->unsignedInteger('count');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['recruiter_id', 'activity_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_manual_activities');
    }
};
