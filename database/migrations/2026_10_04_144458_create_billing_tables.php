<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SaaS-4 (expand): commercial subscription billing. Additive only — no existing value changes.
     *
     * Platform tables: billing_prices (versioned prices of SaaS-3 plan versions; a price is never
     * edited, a new one replaces it — one current per plan version, currency and interval) and
     * billing_invoice_sequences (the platform's single, gap-free invoice series).
     *
     * Tenant tables (tenant_id NOT NULL, composite keys to their tenant parents): billing_customers
     * (one per tenant: the company's billing identity, never a person), billing_subscriptions (at
     * most one live per tenant — unique (tenant_id, is_live), history keeps NULL), billing_invoices
     * (numbered at issue, immutable after), billing_payments (one per provider payment reference).
     *
     * billing_events: provider notifications, unique per provider event id, received before their
     * tenant is known (tenant_id filled in from the local record the event refers to).
     *
     * tenants.access_ends_at: when a past-due grace period or a cancelled subscription's paid
     * period ends — evaluated on every request like trial_ends_at (Tenant::effectiveStatus()).
     */
    public function up(): void
    {
        Schema::create('billing_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_version_id')->constrained('plan_versions')->restrictOnDelete();
            $table->char('currency', 3);
            $table->string('interval', 10);
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 20)->default('active');
            $table->boolean('is_current')->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('provider_price_ref', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plan_version_id', 'currency', 'interval', 'is_current'], 'billing_prices_current_unique');
        });

        Schema::create('billing_invoice_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('series', 40)->unique();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('billing_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('provider', 30)->nullable();
            $table->string('provider_customer_ref', 120)->nullable();
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->foreignId('contact_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tax_id', 40)->nullable();
            $table->text('address')->nullable();
            $table->char('country', 2)->nullable();
            $table->unsignedInteger('state_version')->default(1);
            $table->timestamps();

            $table->unique('tenant_id', 'billing_customers_tenant_unique');
            $table->unique(['tenant_id', 'id'], 'billing_customers_tenant_id_key');
            $table->unique(['provider', 'provider_customer_ref'], 'billing_customers_provider_ref_unique');
        });

        Schema::create('billing_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('billing_customer_id');
            $table->foreignId('plan_version_id')->constrained('plan_versions')->restrictOnDelete();
            $table->foreignId('billing_price_id')->nullable()->constrained('billing_prices')->restrictOnDelete();
            $table->string('source', 20);
            $table->string('provider', 30)->nullable();
            $table->string('provider_subscription_ref', 120)->nullable();
            $table->string('status', 20);
            $table->boolean('is_live')->nullable();
            $table->char('currency', 3);
            $table->string('interval', 10);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_start')->nullable();
            $table->timestamp('trial_end')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('past_due_since')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->string('contract_reference', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'is_live'], 'billing_subscriptions_live_unique');
            $table->unique(['tenant_id', 'id'], 'billing_subscriptions_tenant_id_key');
            $table->unique(['provider', 'provider_subscription_ref'], 'billing_subscriptions_provider_ref_unique');
            $table->index(['tenant_id', 'status'], 'billing_subscriptions_status_idx');
            $table->foreign(['tenant_id', 'billing_customer_id'], 'billing_subscriptions_customer_tfk')->references(['tenant_id', 'id'])->on('billing_customers')->restrictOnDelete();
        });

        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('billing_subscription_id');
            $table->string('number', 40)->nullable()->unique();
            $table->string('status', 20);
            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->json('tax_details')->nullable();
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('amount_paid_minor')->default(0);
            $table->unsignedBigInteger('amount_due_minor');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('description');
            $table->foreignId('plan_version_id')->constrained('plan_versions')->restrictOnDelete();
            $table->foreignId('billing_price_id')->nullable()->constrained('billing_prices')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'billing_invoices_tenant_id_key');
            $table->unique(['billing_subscription_id', 'period_start'], 'billing_invoices_period_unique');
            $table->index(['tenant_id', 'status'], 'billing_invoices_status_idx');
            $table->foreign(['tenant_id', 'billing_subscription_id'], 'billing_invoices_subscription_tfk')->references(['tenant_id', 'id'])->on('billing_subscriptions')->restrictOnDelete();
        });

        Schema::create('billing_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('billing_invoice_id');
            $table->uuid('reference')->unique();
            $table->string('method', 20);
            $table->string('provider', 30)->nullable();
            $table->string('provider_payment_ref', 120)->nullable();
            $table->string('status', 30);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('amount_refunded_minor')->default(0);
            $table->timestamp('attempted_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->string('failure_message')->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'billing_payments_tenant_id_key');
            $table->unique(['provider', 'provider_payment_ref'], 'billing_payments_provider_ref_unique');
            $table->index(['tenant_id', 'billing_invoice_id'], 'billing_payments_invoice_idx');
            $table->foreign(['tenant_id', 'billing_invoice_id'], 'billing_payments_invoice_tfk')->references(['tenant_id', 'id'])->on('billing_invoices')->restrictOnDelete();
        });

        Schema::create('billing_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('provider_event_id', 191);
            $table->string('type', 100);
            $table->string('normalized_type', 40);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->char('payload_hash', 64);
            $table->json('payload')->nullable();
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'billing_events_provider_event_unique');
            $table->index(['status', 'received_at'], 'billing_events_status_idx');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('access_ends_at')->nullable()->after('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('access_ends_at');
        });

        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('billing_payments');
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('billing_subscriptions');
        Schema::dropIfExists('billing_customers');
        Schema::dropIfExists('billing_invoice_sequences');
        Schema::dropIfExists('billing_prices');
    }
};
