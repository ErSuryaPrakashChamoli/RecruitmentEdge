<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8.4: roles get an immutable key, independent of their editable display name, and a
 * protected flag. The six seeded roles are keyed by their current name (deterministic; a role
 * already renamed away from its seeded name stays unkeyed and is reported by identity:audit).
 * CHRO is protected: it cannot be deleted, and renaming it no longer removes its protection.
 */
return new class extends Migration
{
    private const array SEEDED_ROLES = ['chro', 'vp_hr', 'manager', 'assistant_manager', 'recruiter', 'employee'];

    private const array PROTECTED_ROLES = ['chro'];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('key', 50)->nullable()->unique()->after('name');
            $table->boolean('is_protected')->default(false)->after('key');
        });

        foreach (self::SEEDED_ROLES as $key) {
            DB::table('roles')->where('name', $key)->where('guard_name', 'web')->whereNull('key')->update([
                'key' => $key,
                'is_protected' => in_array($key, self::PROTECTED_ROLES, true),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->dropColumn(['key', 'is_protected']);
        });
    }
};
