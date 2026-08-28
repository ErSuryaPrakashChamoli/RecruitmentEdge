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
        Schema::create('candidate_joinings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_application_id')->unique()->constrained('candidate_applications')->restrictOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->date('expected_doj');
            $table->date('actual_doj')->nullable();
            $table->string('status')->default('expected');
            $table->timestamp('confirmed_at')->nullable();
            $table->string('documents_status')->default('pending');
            $table->foreignId('dropout_reason_id')->nullable()->constrained('recruitment_rejection_reasons')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('expected_doj');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_joinings');
    }
};
