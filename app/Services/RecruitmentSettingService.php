<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\RecruitmentSetting;
use App\Models\RecruitmentSettingChange;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.6 (D8.6-012/013): the only writer of the governed recruitment settings
 * (RecruitmentSetting::DEFINITIONS), and the as-of reader for settings that decide historical
 * results.
 *
 * - A change needs `settings.manage` and a reason, passes the cross-field rules, and is recorded in
 *   recruitment_setting_changes (old → new, effective now, actor, reason, request id) and in the
 *   audit log with the reason.
 * - valueAt() gives the value that was in force at a moment: the latest change at or before it;
 *   before the first recorded change, that change's old value; with no recorded change, the
 *   current value. Changes made before 8.6 exist only in the audit log and are not reconstructed.
 */
class RecruitmentSettingService
{
    /**
     * @var array<string, Collection<int, RecruitmentSettingChange>>
     */
    private array $history = [];

    /**
     * Save the given values; only keys whose value changes are written. Returns the changed keys.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, string>
     */
    public function update(User $actor, array $values, ?string $reason): array
    {
        if (! $actor->can('settings.manage')) {
            throw new AuthorizationException('Changing recruitment settings requires settings.manage.');
        }

        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to change the recruitment configuration.');
        }

        $values = collect($values)->only(array_keys(RecruitmentSetting::DEFINITIONS))
            ->map(fn (mixed $value, string $key) => $this->normalise($key, $value))
            ->all();

        $this->validateTogether($values);

        $changed = collect($values)->filter(fn (string $value, string $key) => $this->storedValue($key) !== $value);

        DB::transaction(function () use ($changed, $actor, $reason): void {
            AuditLog::withReason($reason, function () use ($changed, $actor, $reason): void {
                $changed->each(function (string $value, string $key) use ($actor, $reason): void {
                    $definition = RecruitmentSetting::DEFINITIONS[$key];
                    $old = $this->storedValue($key);

                    RecruitmentSetting::put($key, $value, $definition['type'], $definition['group'], $definition['description']);

                    RecruitmentSettingChange::query()->create([
                        'key' => $key,
                        'old_value' => $old,
                        'new_value' => $value,
                        'effective_from' => now(),
                        'changed_by' => $actor->id,
                        'reason' => mb_substr($reason, 0, 1000),
                        'request_id' => Context::get('request_id'),
                    ]);
                });
            });
        });

        $this->history = [];

        return $changed->keys()->all();
    }

    /**
     * The setting's value in force at the given moment, cast to its type.
     */
    public function valueAt(string $key, CarbonInterface $at, mixed $default = null): mixed
    {
        $changes = $this->history[$key] ??= RecruitmentSettingChange::query()
            ->where('key', $key)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get(['id', 'key', 'old_value', 'new_value', 'effective_from']);

        if ($changes->isEmpty()) {
            return RecruitmentSetting::get($key, $default);
        }

        $inForce = $changes->last(fn (RecruitmentSettingChange $change) => $change->effective_from->lte($at));
        $raw = $inForce !== null ? $inForce->new_value : $changes->first()->old_value;

        return $raw === null ? $default : RecruitmentSetting::cast($raw, RecruitmentSetting::DEFINITIONS[$key]['type'] ?? 'string');
    }

    /**
     * Cross-field rules that one field's validation cannot express.
     *
     * @param  array<string, string>  $values
     */
    private function validateTogether(array $values): void
    {
        $value = fn (string $key) => $values[$key] ?? $this->storedValue($key) ?? (string) RecruitmentSetting::DEFINITIONS[$key]['default'];

        if ((int) $value('notification_recruiter_critical_shortfall_percent') >= (int) $value('notification_recruiter_shortfall_percent')) {
            throw new DomainException('The critical underperformance threshold must be below the underperformance threshold.');
        }

        if ((int) $value('position_risk_max_days_open') < (int) $value('vacancy_ageing_alert_days')) {
            throw new DomainException('A position cannot become critical before it counts as ageing — "Position critical after" must be at least the vacancy ageing threshold.');
        }
    }

    private function normalise(string $key, mixed $value): string
    {
        return match (RecruitmentSetting::DEFINITIONS[$key]['type']) {
            'int' => (string) (int) $value,
            'float' => (string) (float) $value,
            default => (string) $value,
        };
    }

    private function storedValue(string $key): ?string
    {
        return RecruitmentSetting::query()->where('key', $key)->value('value');
    }
}
