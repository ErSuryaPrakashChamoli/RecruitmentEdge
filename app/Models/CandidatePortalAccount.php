<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\PreferenceStatus;
use App\Services\Communication\CommunicationPreferenceService;
use Database\Factories\CandidatePortalAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A candidate's login to the candidate portal (Phase 4), authenticated on the separate `candidate`
 * guard. Created, invited and deactivated only through CandidatePortalService. Every portal
 * request resolves data through this account's own candidate — never an id from the request.
 */
#[Fillable(['candidate_id', 'email', 'communication_preferences'])]
#[Hidden(['password', 'remember_token'])]
class CandidatePortalAccount extends Authenticatable
{
    /** @use HasFactory<CandidatePortalAccountFactory> */
    use HasFactory, HasUlids;

    /**
     * Channels a candidate can set a preference for from the portal. Stored in
     * candidate_communication_preferences via CommunicationPreferenceService (the legacy
     * `communication_preferences` JSON column is no longer written — it was backfilled in Phase 5).
     *
     * @var array<int, string>
     */
    public const array COMMUNICATION_CHANNELS = ['email', 'whatsapp', 'sms', 'phone'];

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'communication_preferences' => 'array',
            'invited_at' => 'datetime',
            'password_set_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function displayName(): string
    {
        return ($this->candidate?->full_name ?? 'Candidate').' (portal)';
    }

    /**
     * Shown as "on" in the portal unless the candidate opted out (WhatsApp consent is only given
     * by explicitly ticking it — see CommunicationPreferenceService).
     */
    public function wantsChannel(string $channel): bool
    {
        $status = app(CommunicationPreferenceService::class)->statusFor($this->candidate_id, CommunicationChannel::from($channel));

        return $channel === CommunicationChannel::WhatsApp->value
            ? $status === PreferenceStatus::Allowed
            : $status !== PreferenceStatus::OptedOut;
    }
}
