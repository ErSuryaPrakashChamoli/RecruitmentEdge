<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Candidate portal logins (Phase 4) — deliberately separate from `users` (staff) with their
     * own `candidate` guard, so a candidate session can never reach the admin panel. One account
     * per candidate; `public_id` is the only identifier used in URLs.
     */
    public function up(): void
    {
        Schema::create('candidate_portal_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('candidate_id')->unique()->constrained('candidates')->cascadeOnDelete();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->boolean('is_active')->default(true);
            $table->json('communication_preferences')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('password_set_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_portal_accounts');
    }
};
