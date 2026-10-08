<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.1: a knowledge-base document is only retrievable by the AI once an administrator has
 * declared it contains no candidate or employee personal data. pii_redactions counts the contact
 * details / identifiers the ingestion scrubber removed (a count only, never the values). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_documents', function (Blueprint $table): void {
            $table->timestamp('privacy_declared_at')->nullable()->after('is_published');
            $table->foreignId('privacy_declared_by')->nullable()->after('privacy_declared_at')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('pii_redactions')->default(0)->after('privacy_declared_by');
        });
    }

    public function down(): void
    {
        Schema::table('ai_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('privacy_declared_by');
            $table->dropColumn(['privacy_declared_at', 'pii_redactions']);
        });
    }
};
