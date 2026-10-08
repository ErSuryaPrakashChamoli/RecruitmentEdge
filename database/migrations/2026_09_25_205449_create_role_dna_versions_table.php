<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable Role DNA snapshots. Talent Signals and rediscovery runs record the version they used,
     * so historical analyses stay attributable after the role changes.
     */
    public function up(): void
    {
        Schema::create('role_dna_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_dna_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('dna');
            $table->string('generator', 80);
            $table->string('generator_version', 40);
            $table->string('ai_model', 120)->nullable();
            $table->string('change_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['role_dna_profile_id', 'version'], 'role_dna_versions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_dna_versions');
    }
};
