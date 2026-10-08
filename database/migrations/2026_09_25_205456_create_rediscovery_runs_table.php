<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Talent Rediscovery runs: who searched, against which Role DNA version and rules.
     */
    public function up(): void
    {
        Schema::create('rediscovery_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('recruitment_requisitions')->cascadeOnDelete();
            $table->foreignId('role_dna_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('completed');
            $table->string('rules_version', 40);
            $table->unsignedInteger('candidates_scanned')->default(0);
            $table->unsignedInteger('results_count')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rediscovery_runs');
    }
};
