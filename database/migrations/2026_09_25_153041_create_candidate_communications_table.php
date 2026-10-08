<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per outbound (or provider-reported inbound) candidate message and its delivery
     * state (Phase 5). The candidate timeline gets one event per message pointing here (subject),
     * so this is the message/delivery record, not a second timeline. `idempotency_key` is unique:
     * the same logical message (e.g. one interview's confirmation) can never be created twice.
     */
    public function up(): void
    {
        Schema::create('candidate_communications', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->foreignId('candidate_application_id')->nullable()->constrained('candidate_applications')->nullOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained('recruitment_requisitions')->nullOnDelete();
            $table->foreignId('interview_id')->nullable()->constrained('interviews')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('direction', 10)->default('outbound');
            $table->foreignId('communication_template_id')->nullable();
            $table->foreign('communication_template_id', 'cc_template_foreign')->references('id')->on('communication_templates')->nullOnDelete();
            $table->unsignedInteger('template_version')->nullable();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('recipient');
            $table->string('status', 20);
            $table->string('blocked_reason')->nullable();
            $table->string('trigger', 20)->default('manual');
            $table->string('provider', 40)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('idempotency_key')->unique();
            $table->foreignId('sent_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->boolean('candidate_visible')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['candidate_id', 'created_at'], 'cc_candidate_created_index');
            $table->index(['status', 'channel'], 'cc_status_channel_index');
            $table->index(['provider', 'provider_message_id'], 'cc_provider_message_index');
            $table->index('candidate_application_id', 'cc_application_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_communications');
    }
};
