<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value', 'type', 'group', 'description'])]
class RecruitmentSetting extends Model
{
    use Auditable;

    public const string CACHE_PREFIX = 'recruitment_setting:';

    /**
     * Allowed values for `time_to_hire_start_point` — the start points
     * RecruitmentAnalyticsService::averageTimeToHireDays() understands.
     *
     * @var array<string, string>
     */
    public const array TIME_TO_HIRE_START_POINTS = [
        'candidate_applied' => 'Candidate applied',
        'candidate_sourced' => 'Candidate sourced',
        'requisition_opened' => 'Requisition opened',
    ];

    /**
     * Every typed business-rule setting the application reads, with the same default each reader
     * falls back to. The single source of truth for the Recruitment Configuration page and
     * RecruitmentReferenceDataSeeder — keep a reader's fallback default and this entry in sync.
     *
     * @var array<string, array{type: string, group: string, default: int|float|string, description: string}>
     */
    public const array DEFINITIONS = [
        'sla_days_application_to_screening' => ['type' => 'int', 'group' => 'sla', 'default' => 2, 'description' => 'SLA days: application to screening'],
        'sla_days_shortlist_to_lineup' => ['type' => 'int', 'group' => 'sla', 'default' => 2, 'description' => 'SLA days: shortlist to line-up'],
        'sla_days_lineup_to_interview' => ['type' => 'int', 'group' => 'sla', 'default' => 3, 'description' => 'SLA days: line-up to interview'],
        'sla_days_interview_to_selection' => ['type' => 'int', 'group' => 'sla', 'default' => 3, 'description' => 'SLA days: interview to selection'],
        'sla_days_selection_to_offer' => ['type' => 'int', 'group' => 'sla', 'default' => 3, 'description' => 'SLA days: selection to offer'],
        'sla_days_offer_to_acceptance' => ['type' => 'int', 'group' => 'sla', 'default' => 5, 'description' => 'SLA days: offer to acceptance'],
        'sla_days_selection_to_joining' => ['type' => 'int', 'group' => 'sla', 'default' => 30, 'description' => 'SLA days: selection to joining'],
        'sla_days_time_to_hire_target' => ['type' => 'int', 'group' => 'sla', 'default' => 30, 'description' => 'Target days for overall time to hire'],
        'time_to_hire_start_point' => ['type' => 'string', 'group' => 'hiring', 'default' => 'candidate_applied', 'description' => 'Where time to hire is measured from'],
        'vacancy_ageing_alert_days' => ['type' => 'int', 'group' => 'hiring', 'default' => 30, 'description' => 'Days open before a vacancy counts as ageing'],
        'position_risk_max_days_open' => ['type' => 'int', 'group' => 'hiring', 'default' => 45, 'description' => 'Days open before an unfilled position is critical'],
        'position_risk_min_pipeline_ratio' => ['type' => 'float', 'group' => 'hiring', 'default' => 2.0, 'description' => 'Minimum active pipeline per remaining opening'],
        'candidate_stall_days' => ['type' => 'int', 'group' => 'pipeline', 'default' => 7, 'description' => 'Days without a stage change before a candidate is stalled'],
        'offer_expiry_alert_days' => ['type' => 'int', 'group' => 'pipeline', 'default' => 3, 'description' => 'Days before offer expiry to flag it in the Action Center'],
        'interviewer_daily_capacity' => ['type' => 'int', 'group' => 'pipeline', 'default' => 4, 'description' => 'Maximum interviews per interviewer per day'],
        'joining_risk_followup_days' => ['type' => 'int', 'group' => 'joining', 'default' => 3, 'description' => 'Days before DOJ an unconfirmed joining turns yellow'],
        'joining_reminder_days' => ['type' => 'int', 'group' => 'joining', 'default' => 2, 'description' => 'Days before DOJ to remind the recruiter'],
        'notification_selected_no_offer_hours' => ['type' => 'int', 'group' => 'notifications', 'default' => 24, 'description' => 'Hours a selected candidate can wait for an offer before alerting'],
        'notification_feedback_pending_hours' => ['type' => 'int', 'group' => 'notifications', 'default' => 24, 'description' => 'Hours after an interview before missing feedback alerts'],
        'notification_offer_expiry_warning_days' => ['type' => 'int', 'group' => 'notifications', 'default' => 2, 'description' => 'Days before offer expiry to notify the recruiter'],
        'notification_recruiter_shortfall_percent' => ['type' => 'int', 'group' => 'notifications', 'default' => 70, 'description' => 'Achievement % below which a recruiter or team is underperforming'],
        'notification_recruiter_critical_shortfall_percent' => ['type' => 'int', 'group' => 'notifications', 'default' => 50, 'description' => 'Achievement % below which underperformance escalates to the manager'],
    ];

    /**
     * Resolve a setting value by key, cast to its configured type.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever(
            self::CACHE_PREFIX.$key,
            function () use ($key, $default) {
                $setting = self::query()->where('key', $key)->first();

                return $setting === null ? $default : $setting->castValue();
            },
        );
    }

    /**
     * Create or update a setting. Cache invalidation happens in `booted()` for every save path
     * (this helper, direct Eloquent writes, and Filament's edit form), not just this method.
     */
    public static function put(string $key, mixed $value, string $type = 'string', string $group = 'general', ?string $description = null): self
    {
        return self::query()->updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value, 'type' => $type, 'group' => $group, 'description' => $description],
        );
    }

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => Cache::forget(self::CACHE_PREFIX.$setting->key));
        static::deleted(fn (self $setting) => Cache::forget(self::CACHE_PREFIX.$setting->key));
    }

    protected function castValue(): mixed
    {
        return match ($this->type) {
            'int', 'integer' => (int) $this->value,
            'float', 'decimal' => (float) $this->value,
            'bool', 'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
