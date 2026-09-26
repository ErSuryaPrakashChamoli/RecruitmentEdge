<?php

namespace App\Services\Automation;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\HealthStatus;
use App\Enums\HiringRiskType;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\Priority;
use App\Enums\ReferralStatus;
use App\Enums\RequisitionStatus;
use App\Enums\RiskSeverity;
use App\Enums\SignalBand;
use App\Models\CandidateSource;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HiringHealthSnapshot;
use App\Models\Location;
use App\Models\TalentSignalSnapshot;
use App\Services\Automation\Data\FieldDefinition;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\RecruitmentSlaService;
use BackedEnum;
use Illuminate\Support\Collection;

/**
 * The whitelist of facts a condition can test (Phase 6). Each field reads through the
 * AutomationContext with a fixed resolver; there is no path from rule configuration to arbitrary
 * attributes, queries or code. Operators are fixed per field type.
 */
class AutomationFieldRegistry
{
    /**
     * @var array<string, array<string, string>>
     */
    public const array OPERATORS = [
        'enum' => ['equals' => 'is', 'not_equals' => 'is not', 'in' => 'is one of', 'not_in' => 'is none of', 'exists' => 'is set', 'not_exists' => 'is not set'],
        'string' => ['equals' => 'is', 'not_equals' => 'is not', 'contains' => 'contains', 'not_contains' => 'does not contain', 'in' => 'is one of', 'not_in' => 'is none of', 'exists' => 'is set', 'not_exists' => 'is not set'],
        'number' => ['equals' => '=', 'not_equals' => '≠', 'greater_than' => '>', 'greater_than_or_equal' => '≥', 'less_than' => '<', 'less_than_or_equal' => '≤', 'exists' => 'is set', 'not_exists' => 'is not set'],
        'boolean' => ['equals' => 'is'],
        'datetime' => ['within' => 'is within the next', 'older_than' => 'is more than … ago', 'newer_than' => 'is less than … ago', 'before' => 'is before', 'after' => 'is after', 'exists' => 'is set', 'not_exists' => 'is not set'],
    ];

    /**
     * Operators that take a relative duration ({amount, unit}) as their value.
     */
    public const array DURATION_OPERATORS = ['within', 'older_than', 'newer_than'];

    /**
     * Operators that take no value.
     */
    public const array UNARY_OPERATORS = ['exists', 'not_exists'];

    /**
     * @var array<string, FieldDefinition>|null
     */
    private ?array $fields = null;

    public function __construct(
        private readonly CommunicationPreferenceService $preferences,
        private readonly RecruitmentSlaService $sla,
    ) {}

    /**
     * @return Collection<string, FieldDefinition>
     */
    public function all(): Collection
    {
        return collect($this->fields ??= $this->build());
    }

    public function find(string $key): ?FieldDefinition
    {
        return $this->all()->get($key);
    }

    /**
     * @return array<string, array<string, string>> group => [key => label]
     */
    public function options(): array
    {
        return $this->all()
            ->groupBy(fn (FieldDefinition $field) => $field->group)
            ->map(fn (Collection $group) => $group->mapWithKeys(fn (FieldDefinition $field) => [$field->key => $field->label])->all())
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function operatorsFor(?string $fieldKey): array
    {
        $field = $fieldKey !== null ? $this->find($fieldKey) : null;

        return $field !== null ? self::OPERATORS[$field->type] : [];
    }

    public function resolve(FieldDefinition $field, AutomationContext $context): mixed
    {
        $value = ($field->resolver)($context);

        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /**
     * @return array<string, FieldDefinition>
     */
    private function build(): array
    {
        $enum = fn (string $class): array => collect($class::cases())->mapWithKeys(fn ($case) => [$case->value => method_exists($case, 'label') ? $case->label() : $case->name])->all();

        $fields = [
            // Candidate
            new FieldDefinition('candidate.source', 'Source', 'Candidate', 'enum', fn (AutomationContext $c) => $c->candidate()?->source_id !== null ? (string) $c->candidate()->source_id : null,
                fn () => CandidateSource::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),
            new FieldDefinition('candidate.city', 'Current city', 'Candidate', 'string', fn (AutomationContext $c) => $c->candidate()?->current_city),
            new FieldDefinition('candidate.total_experience', 'Total experience (years)', 'Candidate', 'number', fn (AutomationContext $c) => $c->candidate()?->total_experience),
            new FieldDefinition('candidate.email_allowed', 'Email allowed by preferences', 'Candidate', 'boolean', fn (AutomationContext $c) => $c->candidate() !== null && $this->preferences->blockedReason($c->candidate(), CommunicationChannel::Email) === null),
            new FieldDefinition('candidate.whatsapp_allowed', 'WhatsApp allowed by preferences', 'Candidate', 'boolean', fn (AutomationContext $c) => $c->candidate() !== null && $this->preferences->blockedReason($c->candidate(), CommunicationChannel::WhatsApp) === null),
            new FieldDefinition('candidate.sms_allowed', 'SMS allowed by preferences', 'Candidate', 'boolean', fn (AutomationContext $c) => $c->candidate() !== null && $this->preferences->blockedReason($c->candidate(), CommunicationChannel::Sms) === null),
            new FieldDefinition('candidate.last_reply_at', 'Last reply from candidate', 'Candidate', 'datetime', fn (AutomationContext $c) => $c->candidate()?->communications()->where('direction', 'inbound')->max('created_at')),

            // Application
            new FieldDefinition('application.stage', 'Stage (milestone)', 'Application', 'enum', fn (AutomationContext $c) => $c->application()?->current_stage, fn () => $enum(CandidateStage::class)),
            new FieldDefinition('application.pipeline_stage', 'Configured pipeline stage name', 'Application', 'string', fn (AutomationContext $c) => $c->application()?->pipelineStage?->name),
            new FieldDefinition('application.status', 'Status', 'Application', 'enum', fn (AutomationContext $c) => $c->application()?->status, fn () => $enum(ApplicationStatus::class)),
            new FieldDefinition('application.created_at', 'Application created', 'Application', 'datetime', fn (AutomationContext $c) => $c->application()?->created_at),
            new FieldDefinition('application.last_activity_at', 'Last activity', 'Application', 'datetime', fn (AutomationContext $c) => $c->application()?->last_activity_at),
            new FieldDefinition('application.recruiter', 'Recruiter', 'Application', 'enum', fn (AutomationContext $c) => $c->application()?->recruiter_id !== null ? (string) $c->application()->recruiter_id : null,
                fn () => Employee::query()->orderBy('first_name')->get()->mapWithKeys(fn (Employee $employee) => [(string) $employee->id => $employee->fullName()])->all()),
            new FieldDefinition('application.sla_breached', 'Is breaching its stage SLA', 'Application', 'boolean', fn (AutomationContext $c) => $c->application() !== null && $this->sla->breachFor($c->application()) !== null),
            new FieldDefinition('application.priority', 'Priority', 'Application', 'enum', fn (AutomationContext $c) => $c->application()?->priority, fn () => $enum(Priority::class)),

            // Interview
            new FieldDefinition('interview.status', 'Status', 'Interview', 'enum', fn (AutomationContext $c) => $c->interview()?->status, fn () => $enum(InterviewStatus::class)),
            new FieldDefinition('interview.confirmed', 'Is confirmed', 'Interview', 'boolean', fn (AutomationContext $c) => $c->interview()?->status === InterviewStatus::Confirmed),
            new FieldDefinition('interview.scheduled_at', 'Interview time', 'Interview', 'datetime', fn (AutomationContext $c) => $c->interview()?->scheduled_at),
            new FieldDefinition('interview.result', 'Result', 'Interview', 'enum', fn (AutomationContext $c) => $c->interview()?->result, fn () => $enum(InterviewResult::class)),
            new FieldDefinition('interview.feedback_submitted', 'Has feedback', 'Interview', 'boolean', fn (AutomationContext $c) => $c->interview()?->feedback()->exists() ?? false),
            new FieldDefinition('interview.no_show', 'Candidate did not show', 'Interview', 'boolean', fn (AutomationContext $c) => $c->interview()?->status === InterviewStatus::NoShow),
            new FieldDefinition('interview.round_number', 'Round number', 'Interview', 'number', fn (AutomationContext $c) => $c->interview()?->round_number),
            new FieldDefinition('interview.interviewer', 'Interviewer', 'Interview', 'enum', fn (AutomationContext $c) => $c->interview()?->interviewer_id !== null ? (string) $c->interview()->interviewer_id : null,
                fn () => Employee::query()->orderBy('first_name')->get()->mapWithKeys(fn (Employee $employee) => [(string) $employee->id => $employee->fullName()])->all()),

            // Offer
            new FieldDefinition('offer.status', 'Status', 'Offer', 'enum', fn (AutomationContext $c) => $c->offer()?->status, fn () => $enum(OfferStatus::class)),
            new FieldDefinition('offer.accepted', 'Is accepted', 'Offer', 'boolean', fn (AutomationContext $c) => $c->offer()?->status === OfferStatus::Accepted),
            new FieldDefinition('offer.offer_date', 'Offer date', 'Offer', 'datetime', fn (AutomationContext $c) => $c->offer()?->offer_date),
            new FieldDefinition('offer.offer_expiry', 'Offer expiry', 'Offer', 'datetime', fn (AutomationContext $c) => $c->offer()?->offer_expiry),
            new FieldDefinition('offer.expected_joining_date', 'Expected joining date', 'Offer', 'datetime', fn (AutomationContext $c) => $c->offer()?->expected_joining_date),

            // Joining
            new FieldDefinition('joining.status', 'Status', 'Joining', 'enum', fn (AutomationContext $c) => $c->joining()?->status, fn () => $enum(JoiningStatus::class)),
            new FieldDefinition('joining.expected_doj', 'Expected joining date', 'Joining', 'datetime', fn (AutomationContext $c) => $c->joining()?->expected_doj),
            new FieldDefinition('joining.confirmed', 'Is confirmed', 'Joining', 'boolean', fn (AutomationContext $c) => $c->joining()?->status === JoiningStatus::Confirmed),
            new FieldDefinition('joining.risk', 'Joining risk', 'Joining', 'enum', fn (AutomationContext $c) => $c->joining()?->riskLevel(), ['green' => 'Low (green)', 'yellow' => 'Medium (yellow)', 'red' => 'High (red)']),
            new FieldDefinition('joining.joined', 'Has joined', 'Joining', 'boolean', fn (AutomationContext $c) => $c->joining()?->status === JoiningStatus::Joined),

            // Requisition
            new FieldDefinition('requisition.status', 'Status', 'Requisition', 'enum', fn (AutomationContext $c) => $c->requisition()?->status, fn () => $enum(RequisitionStatus::class)),
            new FieldDefinition('requisition.opening_date', 'Opened on', 'Requisition', 'datetime', fn (AutomationContext $c) => $c->requisition()?->opening_date),
            new FieldDefinition('requisition.pipeline_count', 'Active candidates in pipeline', 'Requisition', 'number', fn (AutomationContext $c) => $c->requisition()?->applications()->where('status', ApplicationStatus::Active)->count()),
            new FieldDefinition('requisition.openings', 'Open positions', 'Requisition', 'number', fn (AutomationContext $c) => $c->requisition()?->openings),
            new FieldDefinition('requisition.priority', 'Priority', 'Requisition', 'enum', fn (AutomationContext $c) => $c->requisition()?->priority, fn () => $enum(Priority::class)),
            new FieldDefinition('requisition.department', 'Department', 'Requisition', 'enum', fn (AutomationContext $c) => $c->requisition()?->department_id !== null ? (string) $c->requisition()->department_id : null,
                fn () => Department::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),
            new FieldDefinition('requisition.location', 'Location', 'Requisition', 'enum', fn (AutomationContext $c) => $c->requisition()?->location_id !== null ? (string) $c->requisition()->location_id : null,
                fn () => Location::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all()),

            // Communication & referral
            new FieldDefinition('communication.status', 'Message status', 'Communication', 'enum', fn (AutomationContext $c) => $c->communication()?->status, fn () => $enum(CommunicationStatus::class)),
            new FieldDefinition('communication.channel', 'Message channel', 'Communication', 'enum', fn (AutomationContext $c) => $c->communication()?->channel, fn () => $enum(CommunicationChannel::class)),
            new FieldDefinition('referral.status', 'Referral status', 'Referral', 'enum', fn (AutomationContext $c) => $c->referral()?->status, fn () => $enum(ReferralStatus::class)),

            // EDGE Intelligence (Phase 7) — persisted, deterministic intelligence as rule facts
            new FieldDefinition('risk.type', 'Risk type', 'EDGE Intelligence', 'enum', fn (AutomationContext $c) => $c->risk()?->type, fn () => $enum(HiringRiskType::class)),
            new FieldDefinition('risk.severity', 'Risk severity', 'EDGE Intelligence', 'enum', fn (AutomationContext $c) => $c->risk()?->severity, fn () => $enum(RiskSeverity::class)),
            new FieldDefinition('requisition.health_status', 'Requisition Hiring Health', 'EDGE Intelligence', 'enum', fn (AutomationContext $c) => $c->requisition() !== null ? HiringHealthSnapshot::query()->where('requisition_id', $c->requisition()->id)->where('is_current', true)->value('status') : null, fn () => $enum(HealthStatus::class)),
            new FieldDefinition('application.talent_signal', 'Candidate Talent Signal band', 'EDGE Intelligence', 'enum', fn (AutomationContext $c) => $c->application() !== null ? TalentSignalSnapshot::query()->where('candidate_id', $c->application()->candidate_id)->where('requisition_id', $c->application()->requisition_id)->where('is_current', true)->value('band') : null, fn () => $enum(SignalBand::class)),
            new FieldDefinition('event.new_requisition_status', 'Requisition status moved to', 'Event', 'enum', fn (AutomationContext $c) => $c->eventData['new_requisition_status'] ?? null, fn () => $enum(RequisitionStatus::class)),

            // Event & time
            new FieldDefinition('event.new_stage', 'Stage moved to', 'Event', 'enum', fn (AutomationContext $c) => $c->eventData['new_stage'] ?? null, fn () => $enum(CandidateStage::class)),
            new FieldDefinition('event.previous_stage', 'Stage moved from', 'Event', 'enum', fn (AutomationContext $c) => $c->eventData['previous_stage'] ?? null, fn () => $enum(CandidateStage::class)),
            new FieldDefinition('event.occurred_at', 'Event time', 'Event', 'datetime', fn (AutomationContext $c) => $c->occurredAt),
        ];

        return collect($fields)->keyBy(fn (FieldDefinition $field) => $field->key)->all();
    }
}
