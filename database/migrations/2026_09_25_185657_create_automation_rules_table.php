<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data-driven automation rules (Phase 6). The editable working copy of conditions, actions,
     * timing and escalation is JSON on the rule; every saved change is frozen into an immutable
     * automation_rule_versions snapshot, and executions point at the version they ran.
     * Reportable facts (trigger, status, scope, owner) are real columns.
     */
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('trigger', 60)->index();
            $table->json('conditions')->nullable();
            $table->json('actions')->nullable();
            $table->json('timing')->nullable();
            $table->json('escalation')->nullable();
            $table->string('scope_type', 20)->default('organization');
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->string('failure_behavior', 20)->default('continue');
            $table->unsignedInteger('cooldown_minutes')->nullable();
            $table->unsignedInteger('max_executions_per_day')->nullable();
            $table->unsignedInteger('max_executions_per_entity')->nullable();
            $table->string('template_key', 80)->nullable();
            $table->unsignedSmallInteger('template_version')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'trigger'], 'automation_rules_status_trigger');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
