<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every verified provider webhook event, keyed by (provider, provider_event_id) so a replayed
     * or retried delivery is processed exactly once. Stores a payload hash, not the payload, to
     * avoid keeping candidate PII from provider callbacks.
     */
    public function up(): void
    {
        Schema::create('communication_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('provider_event_id');
            $table->string('event_type', 60)->nullable();
            $table->string('payload_hash', 64);
            $table->string('status', 20)->default('processed');
            $table->string('note')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'cwe_provider_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_webhook_events');
    }
};
