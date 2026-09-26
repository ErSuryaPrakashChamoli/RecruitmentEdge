<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The single source of truth for a candidate's per-channel communication preference/consent
     * (Phase 5). Backfills the Phase 4 portal preferences (candidate_portal_accounts.
     * communication_preferences JSON), which from now on are read and written through
     * CommunicationPreferenceService.
     */
    public function up(): void
    {
        Schema::create('candidate_communication_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('status', 20)->default('unknown');
            $table->string('source', 40)->nullable();
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->text('reason')->nullable();
            $table->nullableMorphs('updated_by', 'ccp_updated_by_index');
            $table->timestamps();

            $table->unique(['candidate_id', 'channel'], 'ccp_candidate_channel_unique');
        });

        $now = now();

        DB::table('candidate_portal_accounts')
            ->whereNotNull('communication_preferences')
            ->orderBy('id')
            ->each(function ($account) use ($now): void {
                foreach ((array) json_decode($account->communication_preferences, true) as $channel => $allowed) {
                    if (! in_array($channel, ['email', 'sms', 'whatsapp'], true)) {
                        continue;
                    }

                    DB::table('candidate_communication_preferences')->updateOrInsert(
                        ['candidate_id' => $account->candidate_id, 'channel' => $channel],
                        [
                            'status' => $allowed ? 'allowed' : 'opted_out',
                            'source' => 'candidate_portal',
                            'consented_at' => $allowed ? $now : null,
                            'opted_out_at' => $allowed ? null : $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_communication_preferences');
    }
};
