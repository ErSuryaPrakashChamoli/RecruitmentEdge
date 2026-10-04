<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SaaS-1 (expand, step 1 of 4): the tenant entity and staff memberships. Tenants are platform
     * records; memberships link a global staff identity (users) to a tenant.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('slug', 63)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('status', 32)->index();
            $table->timestamp('status_changed_at')->nullable();
            $table->string('timezone', 64);
            $table->string('locale', 16);
            $table->char('currency', 3);
            $table->char('country', 2);
            $table->json('branding')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id'], 'tenant_memberships_tenant_user_unique');
            $table->index(['user_id', 'status'], 'tenant_memberships_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('tenants');
    }
};
