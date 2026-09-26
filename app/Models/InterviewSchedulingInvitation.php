<?php

namespace App\Models;

use Database\Factories\InterviewSchedulingInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recruiter's invitation for an application's candidate to self-schedule. Reached by a
 * temporary signed URL carrying `public_id`, or from the candidate portal.
 */
#[Fillable(['candidate_application_id', 'interviewer_id', 'round_name', 'expires_at', 'created_by'])]
class InterviewSchedulingInvitation extends Model
{
    /** @use HasFactory<InterviewSchedulingInvitationFactory> */
    use HasFactory, HasUlids;

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
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'interviewer_id');
    }

    /**
     * @return HasMany<InterviewSlotBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(InterviewSlotBooking::class, 'invitation_id');
    }

    public function isOpen(): bool
    {
        return $this->revoked_at === null && $this->used_at === null && $this->expires_at->isFuture();
    }
}
