<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.3: interview feedback gets a controlled lifecycle — who submitted it, when it was locked
 * by the interview decision, and corrections as new versions that supersede (never overwrite) the
 * original. Additive: existing rows become version 1, current, unlocked, submitter unknown. The
 * one-per-interviewer unique key gains the version so a correction can sit beside its original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_feedback', function (Blueprint $table): void {
            $table->foreignId('submitted_by')->nullable()->after('feedback')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1)->after('submitted_by');
            $table->boolean('is_current')->default(true)->after('version');
            $table->foreignId('supersedes_id')->nullable()->after('is_current')->constrained('interview_feedback')->nullOnDelete();
            $table->string('correction_reason')->nullable()->after('supersedes_id');
            $table->timestamp('locked_at')->nullable()->after('correction_reason');
        });

        Schema::table('interview_feedback', function (Blueprint $table): void {
            $table->unique(['interview_id', 'interviewer_id', 'version'], 'interview_feedback_interviewer_version');
            $table->index(['interview_id', 'is_current'], 'interview_feedback_current');
        });

        Schema::table('interview_feedback', function (Blueprint $table): void {
            $table->dropUnique(['interview_id', 'interviewer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('interview_feedback', function (Blueprint $table): void {
            $table->unique(['interview_id', 'interviewer_id']);
        });

        Schema::table('interview_feedback', function (Blueprint $table): void {
            $table->dropUnique('interview_feedback_interviewer_version');
            $table->dropIndex('interview_feedback_current');
            $table->dropConstrainedForeignId('supersedes_id');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['version', 'is_current', 'correction_reason', 'locked_at']);
        });
    }
};
