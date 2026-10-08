<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An employee's connected external calendar (Phase 5). OAuth tokens are stored encrypted
     * (Eloquent `encrypted` cast, APP_KEY) and never logged or exposed; client secrets stay in the
     * environment. One connection per employee per provider.
     */
    public function up(): void
    {
        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('account_email')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('calendar_id')->default('primary');
            $table->string('status', 20)->default('active');
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_connections');
    }
};
