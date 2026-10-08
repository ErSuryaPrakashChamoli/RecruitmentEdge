<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role DNA: one profile per requisition, pointing at its current immutable version.
     */
    public function up(): void
    {
        Schema::create('role_dna_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->unique()->constrained('recruitment_requisitions')->cascadeOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('current_version')->default(0);
            $table->string('status', 20)->default('draft');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('ai_status', 20)->default('not_requested');
            $table->timestamp('ai_requested_at')->nullable();
            $table->string('ai_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_dna_profiles');
    }
};
