<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8.7 (D8.7-007 c): whether the provider that accepted a message actually delivers
     * outside this server. A log or array mailer "sends" without anything leaving the building —
     * the row says so instead of claiming the candidate was reached. NULL for messages not (yet)
     * handed to a provider, and for messages sent before 8.7 (not back-filled).
     */
    public function up(): void
    {
        Schema::table('candidate_communications', function (Blueprint $table) {
            $table->boolean('delivered_externally')->nullable()->after('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('candidate_communications', function (Blueprint $table) {
            $table->dropColumn('delivered_externally');
        });
    }
};
