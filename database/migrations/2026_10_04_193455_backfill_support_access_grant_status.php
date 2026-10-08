<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * SaaS-5 (backfill + validate): every support grant created before SaaS-5 (by a tenant, for a
     * support operator — none was ever used) gets its status from its own dates and the least
     * privileged scope, diagnostics. Refuses to finish unless every grant has a known status and
     * every active one has scopes. Idempotent.
     */
    public function up(): void
    {
        $now = now();

        DB::table('support_access_grants')->whereNotNull('revoked_at')->update(['status' => 'revoked']);
        DB::table('support_access_grants')->whereNull('revoked_at')->where('expires_at', '<=', $now)->update(['status' => 'expired']);
        DB::table('support_access_grants')->whereNull('revoked_at')->where('expires_at', '>', $now)->update(['status' => 'active']);
        DB::table('support_access_grants')->whereNull('scopes')->update(['scopes' => json_encode(['diagnostics']), 'requested_scopes' => json_encode(['diagnostics'])]);
        DB::table('support_access_grants')->whereNull('decided_at')->whereNotNull('starts_at')->update(['decided_at' => DB::raw('starts_at')]);

        $unknown = DB::table('support_access_grants')->whereNotIn('status', ['requested', 'active', 'denied', 'revoked', 'expired'])->count();
        $unscoped = DB::table('support_access_grants')->where('status', 'active')->whereNull('scopes')->count();

        if ($unknown + $unscoped > 0) {
            throw new RuntimeException("{$unknown} support grant(s) without a known status, {$unscoped} active without scopes.");
        }
    }

    public function down(): void
    {
        //
    }
};
