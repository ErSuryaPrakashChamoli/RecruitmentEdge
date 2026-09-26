<?php

namespace App\Services\Intelligence;

use App\Enums\IntelligenceAiStatus;
use App\Enums\RequirementLevel;
use App\Enums\RoleDnaCategory;
use App\Jobs\GenerateRoleDnaSuggestionsJob;
use App\Jobs\SummarizeHiringMemoryJob;
use App\Models\AuditLog;
use App\Models\HiringMemoryRecord;
use App\Models\RoleDnaProfile;
use App\Models\User;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\Exceptions\AiProviderUnavailableException;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Gateway\ModelRouter;
use Illuminate\Support\Arr;
use Throwable;

/**
 * The only way EDGE Intelligence (Phase 7) uses AI — always through the existing AiGateway (usage,
 * cost and failures logged there), always in a queued job, never during page rendering, never on
 * hiring decisions:
 *
 * - Role DNA suggestions: schema-constrained output (AiGateway::structured), validated and
 *   fairness-filtered before anything is stored, and stored only as unconfirmed suggestions;
 * - Hiring Memory summaries: a narrative of recorded facts only, labelled AI-generated.
 *
 * Prompts carry role-level data only (designation, department, skills, experience, qualification,
 * public job-posting text) — never candidate names, contact details or compensation. Status is
 * always explicit: processing / available / failed / unavailable.
 */
class IntelligenceAiService
{
    public const string ROLE_DNA_CATEGORY = 'generation';

    public const string SUMMARY_CATEGORY = 'summarization';

    /**
     * Terms that must never appear in an AI suggestion about a role: protected characteristics and
     * common proxies for them.
     */
    public const string FAIRNESS_PATTERN = '/\b(age|aged|young|youthful|old|older|gender|male|female|man|woman|men|women|married|marital|single|religio\w*|caste|nationality|national origin|native|pregnan\w*|disab\w*|health|ethnic\w*|race|racial|culture fit|cultural fit|appearance|photo)\b/i';

    public function __construct(
        private readonly AiGateway $gateway,
        private readonly ModelRouter $router,
        private readonly RoleDnaService $roleDna,
    ) {}

    public function requestRoleDnaSuggestions(RoleDnaProfile $profile, User $actor): IntelligenceAiStatus
    {
        if (! $this->gateway->isConfigured()) {
            $profile->forceFill(['ai_status' => IntelligenceAiStatus::Unavailable, 'ai_error' => 'No AI provider is configured.'])->save();

            return IntelligenceAiStatus::Unavailable;
        }

        $profile->forceFill(['ai_status' => IntelligenceAiStatus::Processing, 'ai_requested_at' => now(), 'ai_error' => null])->save();
        AuditLog::record($profile, 'role_dna_ai_requested', null, ['by_user_id' => $actor->id]);

        try {
            GenerateRoleDnaSuggestionsJob::dispatch($profile->id, $actor->id);
        } catch (AiProviderUnavailableException $e) {
            // Only reachable with the synchronous queue driver, where the job's "retry me" rethrow
            // lands in this request. A request never fails because the AI provider is down.
            $this->fail($profile, 'The AI service is unavailable right now — try again later.', $e);

            return IntelligenceAiStatus::Failed;
        }

        return $profile->fresh()->ai_status;
    }

    /**
     * @param  bool  $retryable  In a queued attempt that can still be retried, a provider outage is
     *                           rethrown so the queue retries it (status stays Processing).
     */
    public function generateRoleDnaSuggestions(RoleDnaProfile $profile, ?User $actor = null, bool $retryable = false): void
    {
        $profile->loadMissing(['requisition.designation', 'requisition.department', 'requisition.jobPosting']);
        $model = $this->router->forCategory(self::ROLE_DNA_CATEGORY);

        try {
            $raw = $this->gateway->structured($this->roleDnaMessages($profile), $this->roleDnaSchema(), self::ROLE_DNA_CATEGORY, $actor);
        } catch (AiProviderUnavailableException $e) {
            if ($retryable) {
                throw $e;
            }

            $this->fail($profile, 'The AI service is unavailable right now — try again later.', $e);

            return;
        } catch (Throwable $e) {
            $this->fail($profile, 'The AI service could not generate suggestions right now.', $e);

            return;
        }

        if ($raw === []) {
            $this->fail($profile, 'The AI service returned no suggestions.');

            return;
        }

        [$accepted, $rejected] = $this->validateRoleDnaSuggestions($raw);
        $version = $this->roleDna->applyAiSuggestions($profile, $accepted, $model);

        $profile->forceFill(['ai_status' => IntelligenceAiStatus::Available, 'ai_error' => $version === null ? 'No new suggestions beyond the current Role DNA.' : null])->save();
        AuditLog::record($profile, 'role_dna_ai_suggestions_received', null, [
            'model' => $model,
            'valid' => count($accepted),
            'rejected_by_validation' => $rejected,
            'stored' => $version?->pendingSuggestions()->count() ?? 0,
            'version' => $version?->version,
        ]);
    }

    /**
     * Validates and fairness-filters raw AI output. Returns [accepted suggestions, rejected count].
     *
     * @param  array<string, mixed>  $raw
     * @return array{0: array<int, array{category: string, label: string, level: string, reason: string|null}>, 1: int}
     */
    public function validateRoleDnaSuggestions(array $raw): array
    {
        $maxItems = (int) config('intelligence.ai.max_items_per_list', 12);
        $maxLength = (int) config('intelligence.ai.max_item_length', 80);
        $lists = [
            'skills' => RoleDnaCategory::Skill,
            'behavioral_indicators' => RoleDnaCategory::Behavioral,
            'interview_dimensions' => RoleDnaCategory::InterviewDimension,
            'responsibilities' => RoleDnaCategory::Responsibility,
        ];
        $accepted = [];
        $rejected = 0;

        foreach ($lists as $key => $category) {
            foreach (array_slice(Arr::wrap($raw[$key] ?? []), 0, $maxItems) as $item) {
                $name = is_array($item) ? trim((string) ($item['name'] ?? '')) : '';
                $reason = is_array($item) && isset($item['reason']) ? mb_substr(trim((string) $item['reason']), 0, 300) : null;
                $level = $category === RoleDnaCategory::Skill && is_array($item) && in_array($item['level'] ?? null, ['required', 'preferred'], true)
                    ? $item['level']
                    : ($category === RoleDnaCategory::Skill ? RequirementLevel::Preferred->value : RequirementLevel::Informational->value);

                $unsafe = $name === ''
                    || mb_strlen($name) > $maxLength
                    || preg_match('/https?:|www\.|<|>|\{\{/i', $name.' '.$reason) === 1
                    || preg_match(self::FAIRNESS_PATTERN, $name.' '.$reason) === 1;

                if ($unsafe) {
                    $rejected++;

                    continue;
                }

                $accepted[] = ['category' => $category->value, 'label' => $name, 'level' => $level, 'reason' => $reason];
            }
        }

        return [$accepted, $rejected];
    }

    public function requestMemorySummary(HiringMemoryRecord $record, User $actor): IntelligenceAiStatus
    {
        if (! $this->gateway->isConfigured()) {
            $record->forceFill(['ai_status' => IntelligenceAiStatus::Unavailable])->save();

            return IntelligenceAiStatus::Unavailable;
        }

        $record->forceFill(['ai_status' => IntelligenceAiStatus::Processing])->save();
        AuditLog::record($record, 'hiring_memory_ai_requested', null, ['by_user_id' => $actor->id]);
        SummarizeHiringMemoryJob::dispatch($record->id, $actor->id);

        return IntelligenceAiStatus::Processing;
    }

    public function summarizeMemory(HiringMemoryRecord $record, ?User $actor = null): void
    {
        $facts = collect($record->facts)->except(['recruiter_id', 'application_code', 'offer_code', 'remarks'])->all();

        try {
            $response = $this->gateway->generate([
                LlmMessage::system('You summarise recorded hiring facts for a recruitment team. Use only the facts given. Do not add, infer or estimate anything, do not judge any person, and do not mention protected characteristics. Two or three plain sentences.'),
                LlmMessage::user("Hiring memory ({$record->memory_type->label()}):\n".json_encode($facts, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR)),
            ], [], self::SUMMARY_CATEGORY, $actor);
        } catch (Throwable $e) {
            report($e);
            $record->forceFill(['ai_status' => IntelligenceAiStatus::Failed])->save();

            return;
        }

        if (! $response->configured || blank($response->content)) {
            $record->forceFill(['ai_status' => IntelligenceAiStatus::Failed])->save();

            return;
        }

        $record->forceFill([
            'ai_summary' => mb_substr(trim($response->content), 0, 2000),
            'ai_status' => IntelligenceAiStatus::Available,
            'ai_model' => $this->router->forCategory(self::SUMMARY_CATEGORY),
            'ai_generated_at' => now(),
        ])->save();
        AuditLog::record($record, 'hiring_memory_ai_summarised', null, ['model' => $record->ai_model]);
    }

    /**
     * Marks AI requests that never completed (no worker, lost job) as failed so they can be asked
     * for again. Changes status only — never calls AI. Returns how many requests were expired.
     */
    public function expireStaleRequests(): int
    {
        $cutoff = now()->subMinutes((int) config('intelligence.ai.stale_after_minutes', 60));

        $profiles = RoleDnaProfile::query()
            ->where('ai_status', IntelligenceAiStatus::Processing)
            ->where(fn ($query) => $query->where('ai_requested_at', '<', $cutoff)->orWhereNull('ai_requested_at'))
            ->update(['ai_status' => IntelligenceAiStatus::Failed, 'ai_error' => 'The AI request did not complete — try again.']);

        $memories = HiringMemoryRecord::query()
            ->where('ai_status', IntelligenceAiStatus::Processing)
            ->where('updated_at', '<', $cutoff)
            ->update(['ai_status' => IntelligenceAiStatus::Failed]);

        return $profiles + $memories;
    }

    /**
     * Role-level context only — no candidate data at all.
     *
     * @return array<int, LlmMessage>
     */
    public function roleDnaMessages(RoleDnaProfile $profile): array
    {
        $requisition = $profile->requisition;
        $context = array_filter([
            'designation' => $requisition->designation?->name,
            'department' => $requisition->department?->name,
            'configured_skills' => array_values($requisition->skills ?? []),
            'experience_years' => $requisition->experience_min !== null || $requisition->experience_max !== null ? IntelligenceText::range($requisition->experience_min, $requisition->experience_max) : null,
            'qualification' => $requisition->qualification,
            'employment_type' => $requisition->employment_type?->label(),
            'public_job_description' => $requisition->jobPosting?->description !== null ? mb_substr(strip_tags((string) $requisition->jobPosting->description), 0, 3000) : null,
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');

        return [
            LlmMessage::system('You help recruiters describe a role. Suggest job-relevant skills, observable behavioural indicators, interview dimensions and core responsibilities for the role described. Only include items directly relevant to doing the job. Never reference age, gender, marital status, religion, caste, nationality, health, disability, appearance, "culture fit" or any other personal characteristic. Keep each item short (under 8 words) and give a one-sentence reason.'),
            LlmMessage::user("Role:\n".json_encode($context, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function roleDnaSchema(): array
    {
        $item = fn (bool $withLevel) => [
            'type' => 'object',
            'properties' => array_filter([
                'name' => ['type' => 'string'],
                'level' => $withLevel ? ['type' => 'string', 'enum' => ['required', 'preferred']] : null,
                'reason' => ['type' => 'string'],
            ]),
            'required' => $withLevel ? ['name', 'level', 'reason'] : ['name', 'reason'],
        ];

        return [
            'type' => 'object',
            'properties' => [
                'skills' => ['type' => 'array', 'items' => $item(true)],
                'behavioral_indicators' => ['type' => 'array', 'items' => $item(false)],
                'interview_dimensions' => ['type' => 'array', 'items' => $item(false)],
                'responsibilities' => ['type' => 'array', 'items' => $item(false)],
            ],
            'required' => ['skills', 'behavioral_indicators', 'interview_dimensions', 'responsibilities'],
        ];
    }

    private function fail(RoleDnaProfile $profile, string $message, ?Throwable $e = null): void
    {
        if ($e !== null) {
            report($e);
        }

        $profile->forceFill(['ai_status' => IntelligenceAiStatus::Failed, 'ai_error' => $message])->save();
        AuditLog::record($profile, 'role_dna_ai_failed', null, ['error' => $message]);
    }
}
