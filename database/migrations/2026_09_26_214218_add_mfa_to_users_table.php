<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.4: authenticator-app MFA (Filament) — the secret and recovery codes are stored encrypted
 * and are hidden attributes, so they never reach serialization, the audit log or AI. mfa_enabled_at
 * is when the person last enrolled (shown in the access review). Additive; nobody is enrolled yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('app_authentication_secret')->nullable()->after('password');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
            $table->timestamp('mfa_enabled_at')->nullable()->after('app_authentication_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes', 'mfa_enabled_at']);
        });
    }
};
