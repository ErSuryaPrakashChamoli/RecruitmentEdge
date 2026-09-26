<?php

namespace App\Services;

use App\Enums\FeedbackRecommendation;
use App\Models\AuditLog;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\User;
use App\Services\Lifecycle\LifecycleGuard;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.3: the only writer of interview feedback.
 *
 * - Attribution is fixed: feedback always belongs to the interview's assigned interviewer. It may
 *   be submitted by that interviewer, or on their behalf by a hiring/HR user who can manage the
 *   interview (interviews.manage within scope) — the submitter is recorded either way.
 * - Feedback is submitted while the interview is still open; completing the interview (the round's
 *   decision) locks it.
 * - A correction never edits the original: it adds a new version with a reason, the original stays
 *   as history, and the correction inherits the lock.
 *
 * Audit rows carry ids, the recommendation and the score — never the feedback text.
 */
class InterviewFeedbackService
{
    /**
     * @param  array{recommendation: FeedbackRecommendation|string, feedback: string, score?: float|int|string|null, ratings?: array<string, mixed>|null}  $data
     */
    public function submit(Interview $interview, array $data, User $actor): InterviewFeedback
    {
        if (! $this->canSubmit($actor, $interview)) {
            throw new DomainException('Only the assigned interviewer, or someone who manages this interview, can record its feedback.');
        }

        if ($interview->status->isTerminal()) {
            throw new DomainException("Feedback can no longer be added: the interview is {$interview->status->label()}. Use a correction instead.");
        }

        if ($interview->feedback()->where('interviewer_id', $interview->interviewer_id)->exists()) {
            throw new DomainException('Feedback from this interviewer is already recorded — use a correction to change it.');
        }

        return DB::transaction(fn (): InterviewFeedback => LifecycleGuard::allow(function () use ($interview, $data, $actor): InterviewFeedback {
            $feedback = InterviewFeedback::query()->create([
                ...$this->content($data),
                'interview_id' => $interview->id,
                'interviewer_id' => $interview->interviewer_id,
                'submitted_by' => $actor->id,
                'version' => 1,
                'is_current' => true,
            ]);

            AuditLog::record($feedback, 'interview_feedback_submitted', null, $this->auditFacts($feedback, $actor));

            return $feedback;
        }));
    }

    /**
     * @param  array{recommendation: FeedbackRecommendation|string, feedback: string, score?: float|int|string|null, ratings?: array<string, mixed>|null}  $data
     */
    public function correct(InterviewFeedback $feedback, array $data, string $reason, User $actor): InterviewFeedback
    {
        $reason = trim($reason);

        if (! $this->canCorrect($actor, $feedback)) {
            throw new DomainException('You are not allowed to correct this feedback.');
        }

        if ($reason === '') {
            throw new DomainException('A reason is required to correct feedback.');
        }

        return DB::transaction(fn (): InterviewFeedback => LifecycleGuard::allow(function () use ($feedback, $data, $reason, $actor): InterviewFeedback {
            $current = InterviewFeedback::query()->whereKey($feedback->id)->lockForUpdate()->firstOrFail();

            if (! $current->is_current) {
                throw new DomainException('This feedback has already been corrected — correct the current version.');
            }

            $current->update(['is_current' => false]);

            $correction = InterviewFeedback::query()->create([
                ...$this->content($data),
                'interview_id' => $current->interview_id,
                'interviewer_id' => $current->interviewer_id,
                'submitted_by' => $actor->id,
                'version' => $current->version + 1,
                'is_current' => true,
                'supersedes_id' => $current->id,
                'correction_reason' => mb_substr($reason, 0, 255),
                'locked_at' => $current->locked_at,
            ]);

            AuditLog::record($correction, 'interview_feedback_corrected', $this->auditFacts($current, $actor), [...$this->auditFacts($correction, $actor), 'reason' => $correction->correction_reason]);

            return $correction;
        }));
    }

    /**
     * Locks every current feedback entry of an interview (called when the interview is completed —
     * the round's hiring decision). Idempotent.
     */
    public function lock(Interview $interview): int
    {
        return LifecycleGuard::allow(fn (): int => InterviewFeedback::query()
            ->where('interview_id', $interview->id)
            ->whereNull('locked_at')
            ->get()
            ->each(fn (InterviewFeedback $feedback) => $feedback->update(['locked_at' => now()]))
            ->count());
    }

    public function canSubmit(User $user, Interview $interview): bool
    {
        return ($user->employee_id !== null && (int) $user->employee_id === (int) $interview->interviewer_id)
            || $user->can('update', $interview);
    }

    public function canCorrect(User $user, InterviewFeedback $feedback): bool
    {
        return $feedback->is_current && $user->can('update', $feedback->interview);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function content(array $data): array
    {
        $recommendation = $data['recommendation'] instanceof FeedbackRecommendation ? $data['recommendation'] : FeedbackRecommendation::tryFrom((string) ($data['recommendation'] ?? ''));

        if ($recommendation === null || blank($data['feedback'] ?? null)) {
            throw new DomainException('Feedback needs a recommendation and written comments.');
        }

        return [
            'recommendation' => $recommendation,
            'feedback' => trim((string) $data['feedback']),
            'score' => filled($data['score'] ?? null) ? $data['score'] : null,
            'ratings' => $data['ratings'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditFacts(InterviewFeedback $feedback, User $actor): array
    {
        return [
            'interview_id' => $feedback->interview_id,
            'interviewer_id' => $feedback->interviewer_id,
            'version' => $feedback->version,
            'recommendation' => $feedback->recommendation?->value,
            'score' => $feedback->score,
            'by_user_id' => $actor->id,
        ];
    }
}
