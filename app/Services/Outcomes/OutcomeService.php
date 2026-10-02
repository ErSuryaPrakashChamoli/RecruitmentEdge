<?php

namespace App\Services\Outcomes;

use App\Enums\OutcomeCaptureMode;
use App\Enums\OutcomeConfidence;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeState;
use App\Enums\OutcomeType;
use App\Models\AuditLog;
use App\Models\EmployeeSeparation;
use App\Models\HiringOutcome;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Outcome Loop™ (Phase 8.2): the only writer of hiring outcomes.
 *
 * - record() is idempotent: the same dedupe key with the same result is a no-op, so evaluating or
 *   backfilling twice changes nothing. A different automatic result supersedes the current version
 *   (kept, is_current = false) — never an in-place overwrite.
 * - A human correction is never overridden by a later automatic recalculation — with one narrow
 *   exception (Phase 8.5, DF-11): an outcome voided because the separation it cited was cancelled
 *   is observed again. The void stays in history; the new observation is a new, audited version.
 * - correct(), void() and confirm() need an authorized person (and a reason where it changes the
 *   result) and are audited with previous and new values — ids and labels only, no personal data.
 */
class OutcomeService
{
    public const string RULE_VERSION = 'outcome-rules/1';

    /**
     * @param  array{result: OutcomeResult, state?: OutcomeState, confidence: OutcomeConfidence, value?: float|int|null, unit?: string|null, source?: Model|null, observed_at?: mixed, observation_start?: mixed, observation_end?: mixed, details?: array<string, mixed>|null, hiring_outcome_snapshot_id?: int|null, candidate_application_id?: int|null, requisition_id?: int|null, employee_id?: int|null, offer_id?: int|null, candidate_joining_id?: int|null}  $data
     */
    public function record(OutcomeType $type, string $dedupeKey, array $data, OutcomeCaptureMode $mode = OutcomeCaptureMode::ObservedGoingForward): HiringOutcome
    {
        // Phase 8.9 (P89-PERF-021): the locking read of a key not recorded yet takes a gap lock, so two
        // first-time outcomes recorded at once can deadlock on insert. As its own transaction it is
        // simply run again (MySQL has rolled the loser back); inside a caller's transaction the
        // deadlock propagates to that caller, as before.
        return DB::transaction(function () use ($type, $dedupeKey, $data, $mode): HiringOutcome {
            $current = HiringOutcome::query()->where('dedupe_key', $dedupeKey)->current()->lockForUpdate()->first();
            $attributes = $this->attributes($type, $dedupeKey, $data, $mode);

            $reobserving = $current !== null && $this->isReobservable($current);

            if ($current !== null && ! $reobserving && ($current->capture_mode === OutcomeCaptureMode::ManualCorrection || $this->sameOutcome($current, $attributes))) {
                return $current;
            }

            if ($current === null) {
                return HiringOutcome::query()->create($attributes);
            }

            $current->update(['is_current' => false]);
            $next = HiringOutcome::query()->create([...$attributes, 'version' => $current->version + 1, 'supersedes_id' => $current->id]);
            AuditLog::record($next, $reobserving ? 'outcome_reobserved' : 'outcome_superseded', ['version' => $current->version, 'state' => $current->state->value, 'result' => $current->result->value], ['version' => $next->version, 'result' => $next->result->value, 'rule_version' => $next->rule_version]);

            return $next;
        }, attempts: 3);
    }

    /**
     * Phase 8.5 (DF-11): whether a current outcome is a void caused by a cancelled separation — the
     * only human correction a later observation may supersede. Manual voids and corrections for any
     * other reason are never overridden.
     */
    public function isReobservable(HiringOutcome $outcome): bool
    {
        if ($outcome->state !== OutcomeState::Void || $outcome->source_type !== (new EmployeeSeparation)->getMorphClass() || $outcome->source_id === null) {
            return false;
        }

        return EmployeeSeparation::query()->whereKey($outcome->source_id)->whereNotNull('cancelled_at')->exists();
    }

    public function correct(HiringOutcome $outcome, OutcomeResult $result, ?float $value, string $reason, User $actor): HiringOutcome
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A correction needs a reason.');
        }

        return $this->newVersion($outcome, $actor, [
            'result' => $result,
            'value' => $value,
            'state' => OutcomeState::Confirmed,
            'capture_mode' => OutcomeCaptureMode::ManualCorrection,
            'correction_reason' => mb_substr($reason, 0, 255),
        ], 'outcome_corrected');
    }

    public function void(HiringOutcome $outcome, string $reason, User $actor): HiringOutcome
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Voiding an outcome needs a reason.');
        }

        return $this->newVersion($outcome, $actor, [
            'state' => OutcomeState::Void,
            'capture_mode' => OutcomeCaptureMode::ManualCorrection,
            'correction_reason' => mb_substr($reason, 0, 255),
        ], 'outcome_voided');
    }

    public function confirm(HiringOutcome $outcome, User $actor): HiringOutcome
    {
        if ($outcome->state === OutcomeState::Unknown) {
            throw new DomainException('An unknown outcome cannot be confirmed — record a correction with the observed result instead.');
        }

        return $this->newVersion($outcome, $actor, ['state' => OutcomeState::Confirmed], 'outcome_confirmed');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function newVersion(HiringOutcome $outcome, User $actor, array $changes, string $action): HiringOutcome
    {
        return DB::transaction(function () use ($outcome, $actor, $changes, $action): HiringOutcome {
            $current = HiringOutcome::query()->whereKey($outcome->id)->lockForUpdate()->firstOrFail();

            if (! $current->is_current) {
                throw new DomainException('This outcome has already been superseded — work on the current version.');
            }

            $to = $changes['state'] ?? $current->state;

            if ($to !== $current->state && ! $current->state->canTransitionTo($to)) {
                throw new DomainException("An outcome cannot move from {$current->state->label()} to {$to->label()}.");
            }

            $current->update(['is_current' => false]);
            $next = HiringOutcome::query()->create([
                ...collect($current->getAttributes())->except(['id', 'created_at', 'updated_at', 'version', 'supersedes_id', 'is_current', 'corrected_by', 'correction_reason', 'details'])->all(),
                'details' => $current->details,
                ...$changes,
                'version' => $current->version + 1,
                'supersedes_id' => $current->id,
                'is_current' => true,
                'corrected_by' => $actor->id,
            ]);

            AuditLog::record($next, $action, [
                'version' => $current->version, 'state' => $current->state->value, 'result' => $current->result->value, 'value' => $current->value,
            ], [
                'version' => $next->version, 'state' => $next->state->value, 'result' => $next->result->value, 'value' => $next->value,
                'reason' => $changes['correction_reason'] ?? null, 'by_user_id' => $actor->id,
            ]);

            return $next;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(OutcomeType $type, string $dedupeKey, array $data, OutcomeCaptureMode $mode): array
    {
        $source = $data['source'] ?? null;

        return [
            'outcome_type' => $type,
            'category' => $type->category(),
            'state' => $data['state'] ?? ($data['result']->isObserved() ? OutcomeState::Observed : OutcomeState::Unknown),
            'result' => $data['result'],
            'value' => $data['value'] ?? null,
            'unit' => $data['unit'] ?? null,
            'confidence' => $data['confidence'],
            'capture_mode' => $mode,
            'hiring_outcome_snapshot_id' => $data['hiring_outcome_snapshot_id'] ?? null,
            'candidate_application_id' => $data['candidate_application_id'] ?? null,
            'requisition_id' => $data['requisition_id'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'offer_id' => $data['offer_id'] ?? null,
            'candidate_joining_id' => $data['candidate_joining_id'] ?? null,
            'observation_start' => $data['observation_start'] ?? null,
            'observation_end' => $data['observation_end'] ?? null,
            'observed_at' => $data['observed_at'] ?? now(),
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'rule_version' => self::RULE_VERSION,
            'details' => $data['details'] ?? null,
            'dedupe_key' => $dedupeKey,
            'version' => 1,
            'is_current' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function sameOutcome(HiringOutcome $current, array $attributes): bool
    {
        $value = $attributes['value'] === null ? null : round((float) $attributes['value'], 2);

        return $current->result === $attributes['result']
            && $current->state === $attributes['state']
            && $current->confidence === $attributes['confidence']
            && ($current->value === null ? null : round((float) $current->value, 2)) === $value;
    }
}
