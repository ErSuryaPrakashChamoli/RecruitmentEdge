<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\CommunicationTrigger;
use Database\Factories\CandidateCommunicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One candidate message and its delivery state (Phase 5). Created only by CommunicationService;
 * status moves only through CommunicationService/SendCommunicationJob (provider result) and
 * verified provider webhooks — never set by hand, and never "delivered" without the provider
 * saying so.
 */
#[Fillable([
    'candidate_id',
    'candidate_application_id',
    'requisition_id',
    'interview_id',
    'channel',
    'direction',
    'communication_template_id',
    'template_version',
    'communication_template_version_id',
    'subject',
    'body',
    'recipient',
    'trigger',
    'idempotency_key',
    'sent_by',
    'candidate_visible',
    'metadata',
])]
class CandidateCommunication extends Model
{
    /** @use HasFactory<CandidateCommunicationFactory> */
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
            'channel' => CommunicationChannel::class,
            'status' => CommunicationStatus::class,
            'trigger' => CommunicationTrigger::class,
            'candidate_visible' => 'boolean',
            'metadata' => 'array',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return BelongsTo<Interview, $this>
     */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class);
    }

    /**
     * @return BelongsTo<CommunicationTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplate::class, 'communication_template_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sent_by');
    }
}
