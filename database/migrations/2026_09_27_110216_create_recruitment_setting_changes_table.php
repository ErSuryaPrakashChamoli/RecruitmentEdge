<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.6 (D8.6-012): the history of every governed recruitment setting — each change with its
 * old and new value, when it took effect, who made it and why. Append-only. The value in force at
 * any moment is reproducible from here (RecruitmentSettingService::valueAt); the SLA metric and the
 * time-to-hire target read targets as of the event, not the current value. Changes made before
 * 8.6 are not reconstructed (they remain in the audit log only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_setting_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('key');
            $table->text('old_value')->nullable();
            $table->text('new_value');
            $table->timestamp('effective_from');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['key', 'effective_from'], 'setting_changes_key_effective');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_setting_changes');
    }
};
