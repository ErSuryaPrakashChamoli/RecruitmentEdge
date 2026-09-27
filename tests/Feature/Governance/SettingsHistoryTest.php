<?php

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Filament\Resources\RecruitmentSettings\Pages\ManageRecruitmentConfiguration;
use App\Filament\Resources\RecruitmentSettings\RecruitmentSettingResource;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\RecruitmentSetting;
use App\Models\RecruitmentSettingChange;
use App\Models\User;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricService;
use App\Services\RecruitmentSettingService;
use App\Services\RecruitmentSlaService;
use Carbon\CarbonImmutable;
use Database\Seeders\RecruitmentReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.6 settings governance (D8.6-012/013): every change has a reason and a history row;
 * historical SLA compliance and time-to-hire status use the target in force at the time; the raw
 * key/value editor is read-only.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(RecruitmentReferenceDataSeeder::class);
    $this->admin = User::factory()->create()->assignRole('chro');
    actingAs($this->admin);
    $this->settings = app(RecruitmentSettingService::class);
});

function selectionToOfferLegs(int $count, int $days, CarbonImmutable $endedAt): void
{
    foreach (range(1, $count) as $ignored) {
        $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::OfferReleased]);
        $application->stageHistory()->forceCreate(['previous_stage' => null, 'new_stage' => CandidateStage::Selected, 'created_at' => $endedAt->subDays($days)]);
        $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Selected, 'new_stage' => CandidateStage::OfferReleased, 'created_at' => $endedAt]);
    }
}

function selectionToOfferLeg(MetricPeriod $period): array
{
    Cache::flush();

    return collect(app(MetricService::class)->get('sla.leg_compliance', MetricQuery::make($period, null))->detail('legs'))
        ->firstWhere('label', 'Selection -> Offer');
}

test('FAILURE 4: past SLA compliance keeps the target that was in force; the new target applies from the change on', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-20 12:00:00'));
    $this->settings->update($this->admin, ['sla_days_selection_to_offer' => 2], 'Initial agreement with hiring managers');
    selectionToOfferLegs(3, 3, CarbonImmutable::parse('2026-08-25 12:00:00'));

    $this->travelTo(CarbonImmutable::parse('2026-09-05 09:00:00'));
    $this->settings->update($this->admin, ['sla_days_selection_to_offer' => 5], 'Offers now need finance approval');
    selectionToOfferLegs(3, 3, CarbonImmutable::parse('2026-09-06 12:00:00'));
    $this->travelTo(CarbonImmutable::parse('2026-09-20 09:00:00'));

    $august = selectionToOfferLeg(MetricPeriod::between(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31')));
    $september = selectionToOfferLeg(MetricPeriod::between(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30')));

    expect($august['compliance_percent'])->toBe(0.0)
        ->and($august['target_days'])->toBe(2)
        ->and($august['targets_applied'])->toBe([2])
        ->and($september['compliance_percent'])->toBe(100.0)
        ->and($september['target_days'])->toBe(5);
});

test('a leg ending exactly when a change takes effect uses the new target; before the first change, the old value', function (): void {
    $change = CarbonImmutable::parse('2026-09-10 10:00:00');
    RecruitmentSetting::put('sla_days_selection_to_offer', '5', 'int');
    RecruitmentSettingChange::query()->create(['key' => 'sla_days_selection_to_offer', 'old_value' => '2', 'new_value' => '5', 'effective_from' => $change, 'reason' => 'x']);

    expect($this->settings->valueAt('sla_days_selection_to_offer', $change->subSecond()))->toBe(2)
        ->and($this->settings->valueAt('sla_days_selection_to_offer', $change))->toBe(5)
        ->and($this->settings->valueAt('sla_days_selection_to_offer', $change->subYears(3)))->toBe(2);
});

test('with no recorded history the current value applies, and same-instant changes resolve to the latest', function (): void {
    RecruitmentSetting::put('sla_days_offer_to_acceptance', '9', 'int');
    expect($this->settings->valueAt('sla_days_offer_to_acceptance', now()->subYear()))->toBe(9);

    $at = CarbonImmutable::parse('2026-09-01 00:00:00');
    RecruitmentSettingChange::query()->create(['key' => 'sla_days_offer_to_acceptance', 'old_value' => '9', 'new_value' => '4', 'effective_from' => $at, 'reason' => 'a']);
    RecruitmentSettingChange::query()->create(['key' => 'sla_days_offer_to_acceptance', 'old_value' => '4', 'new_value' => '6', 'effective_from' => $at, 'reason' => 'b']);

    expect(app(RecruitmentSettingService::class)->valueAt('sla_days_offer_to_acceptance', $at->addHour()))->toBe(6);
});

test('the time-to-hire status of a past period uses the target in force at its end', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-08-31 18:00:00'));
    $this->settings->update($this->admin, ['sla_days_time_to_hire_target' => 30], 'Baseline');
    foreach ([20, 22, 25] as $days) {
        CandidateJoining::factory()->create([
            'candidate_application_id' => CandidateApplication::factory()->create(['application_date' => now()->subDays($days)])->id,
            'status' => JoiningStatus::Joined,
            'actual_doj' => now(),
        ]);
    }

    $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00'));
    $this->settings->update($this->admin, ['sla_days_time_to_hire_target' => 15], 'Tighter target');
    Cache::flush();

    $august = app(RecruitmentSlaService::class)->timeToHireSummary(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31')->endOfDay());

    expect($august['target_days'])->toBe(30)
        ->and($august['status'])->toBe('on_track');
});

test('FAILURE 8: a configuration change needs a reason and settings.manage, and is recorded with both', function (): void {
    $manager = User::factory()->create()->assignRole('vp_hr');

    expect(fn () => $this->settings->update($this->admin, ['candidate_stall_days' => 9], ''))->toThrow(DomainException::class, 'reason')
        ->and(fn () => $this->settings->update($manager, ['candidate_stall_days' => 9], 'why'))->toThrow(AuthorizationException::class);

    $changed = $this->settings->update($this->admin, ['candidate_stall_days' => 9, 'joining_reminder_days' => RecruitmentSetting::get('joining_reminder_days')], 'Stalls reviewed weekly');

    $history = RecruitmentSettingChange::query()->where('key', 'candidate_stall_days')->sole();
    $audit = AuditLog::query()->where('auditable_type', RecruitmentSetting::class)->where('action', 'updated')->latest('id')->first();

    expect($changed)->toBe(['candidate_stall_days'])
        ->and($history->old_value)->toBe('7')
        ->and($history->new_value)->toBe('9')
        ->and($history->changed_by)->toBe($this->admin->id)
        ->and($history->reason)->toBe('Stalls reviewed weekly')
        ->and($audit->reason)->toBe('Stalls reviewed weekly')
        ->and(RecruitmentSetting::get('candidate_stall_days'))->toBe(9);
});

test('cross-field rules are enforced', function (): void {
    expect(fn () => $this->settings->update($this->admin, ['notification_recruiter_critical_shortfall_percent' => 80, 'notification_recruiter_shortfall_percent' => 70], 'x'))->toThrow(DomainException::class, 'critical')
        ->and(fn () => $this->settings->update($this->admin, ['position_risk_max_days_open' => 10, 'vacancy_ageing_alert_days' => 30], 'x'))->toThrow(DomainException::class, 'ageing');
});

test('the configuration page will not save without a reason', function (): void {
    Livewire::test(ManageRecruitmentConfiguration::class)
        ->fillForm(['candidate_stall_days' => 11])
        ->call('save')
        ->assertHasFormErrors(['change_reason' => 'required']);

    Livewire::test(ManageRecruitmentConfiguration::class)
        ->fillForm(['candidate_stall_days' => 11, 'change_reason' => 'Faster follow-up'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Recruitment configuration saved');

    expect(RecruitmentSetting::get('candidate_stall_days'))->toBe(11);
});

test('the raw settings resource is read-only', function (): void {
    $setting = RecruitmentSetting::query()->where('key', 'candidate_stall_days')->sole();

    expect(RecruitmentSettingResource::getPages())->not->toHaveKeys(['create', 'edit'])
        ->and($this->admin->can('create', RecruitmentSetting::class))->toBeFalse()
        ->and($this->admin->can('update', $setting))->toBeFalse()
        ->and($this->admin->can('delete', $setting))->toBeFalse();
});

test('a missing setting never caches the first caller\'s fallback', function (): void {
    expect(RecruitmentSetting::get('not_configured_anywhere', 5))->toBe(5)
        ->and(RecruitmentSetting::get('not_configured_anywhere', 7))->toBe(7);
});
