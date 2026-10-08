<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable configuration snapshots. An execution always records the version it ran, so an
     * administrator editing a rule never changes the meaning of past executions.
     */
    public function up(): void
    {
        Schema::create('automation_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('change_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['automation_rule_id', 'version'], 'automation_rule_versions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rule_versions');
    }
};
