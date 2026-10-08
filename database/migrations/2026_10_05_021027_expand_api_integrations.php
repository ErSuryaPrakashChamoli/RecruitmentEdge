<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SaaS-6 (expand): the tenant API, integration connections and webhooks. Additive only — six new
     * tenant-owned tables (tenant_id NOT NULL, restrict on delete, composite tenant keys for every
     * reference between them). Nothing existing changes.
     *
     * - api_credentials: a tenant member's API keys — opaque key id, SHA-256 of the secret (the secret
     *   itself is never stored), scopes, expiry, revocation, last use.
     * - api_idempotency_keys: one row per (credential, Idempotency-Key): the request fingerprint and
     *   the encrypted response to replay.
     * - integration_connections: the tenant's webhook connections (outbound endpoints, inbound
     *   sources): non-secret configuration, encrypted secrets, health.
     * - webhook_events / webhook_deliveries: outbound events (thin payloads) and one delivery per
     *   (event, endpoint).
     * - inbound_webhook_events: verified inbound deliveries, once per (connection, sender event id),
     *   payload encrypted.
     */
    public function up(): void
    {
        Schema::create('api_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('key_id', 32)->unique();
            $table->char('secret_hash', 64);
            $table->json('scopes');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'api_credentials_tenant_name_unique');
            $table->unique(['tenant_id', 'id'], 'api_credentials_tenant_id_key');
            $table->index(['tenant_id', 'revoked_at'], 'api_credentials_active_idx');
        });

        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('api_credential_id');
            $table->string('idempotency_key', 191);
            $table->string('method', 10);
            $table->string('route', 100);
            $table->char('request_hash', 64);
            $table->string('status', 20);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_encrypted')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'api_credential_id', 'idempotency_key'], 'api_idem_key_unique');
            $table->index(['tenant_id', 'expires_at'], 'api_idem_expiry_idx');
            $table->foreign(['tenant_id', 'api_credential_id'], 'api_idem_credential_tfk')->references(['tenant_id', 'id'])->on('api_credentials')->cascadeOnDelete();
        });

        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('type', 40);
            $table->string('name', 80);
            $table->string('status', 20);
            $table->string('public_key', 40)->nullable()->unique();
            $table->json('config');
            $table->text('secrets');
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disable_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'int_conn_tenant_name_unique');
            $table->unique(['tenant_id', 'id'], 'int_conn_tenant_id_key');
            $table->index(['tenant_id', 'type', 'status'], 'int_conn_type_status_idx');
        });

        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('event_key', 40)->unique();
            $table->string('type', 60);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'webhook_events_tenant_id_key');
            $table->index(['tenant_id', 'occurred_at'], 'webhook_events_occurred_idx');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('webhook_event_id');
            $table->unsignedBigInteger('integration_connection_id');
            $table->string('delivery_key', 40)->unique();
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedSmallInteger('replay_count')->default(0);
            $table->timestamps();

            $table->unique(['webhook_event_id', 'integration_connection_id'], 'webhook_deliveries_event_conn_unique');
            $table->index(['tenant_id', 'status', 'next_attempt_at'], 'webhook_deliveries_due_idx');
            $table->index(['tenant_id', 'integration_connection_id', 'id'], 'webhook_deliveries_conn_idx');
            $table->foreign(['tenant_id', 'webhook_event_id'], 'webhook_deliveries_event_tfk')->references(['tenant_id', 'id'])->on('webhook_events')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'integration_connection_id'], 'webhook_deliveries_conn_tfk')->references(['tenant_id', 'id'])->on('integration_connections')->cascadeOnDelete();
        });

        Schema::create('inbound_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('integration_connection_id');
            $table->string('external_id', 191);
            $table->string('type', 100);
            $table->longText('payload_encrypted');
            $table->char('payload_hash', 64);
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('claimed_until')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->json('result')->nullable();
            $table->unsignedSmallInteger('reprocess_count')->default(0);
            $table->timestamps();

            $table->unique(['integration_connection_id', 'external_id'], 'inbound_events_conn_external_unique');
            $table->index(['tenant_id', 'status', 'received_at'], 'inbound_events_status_idx');
            $table->foreign(['tenant_id', 'integration_connection_id'], 'inbound_events_conn_tfk')->references(['tenant_id', 'id'])->on('integration_connections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_webhook_events');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('integration_connections');
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('api_credentials');
    }
};
