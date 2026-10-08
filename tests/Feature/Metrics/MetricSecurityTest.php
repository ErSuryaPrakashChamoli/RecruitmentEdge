<?php

use App\Enums\OfferStatus;
use App\Filament\Exports\OfferExporter;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Offers\Pages\ListOffers;
use App\Filament\Widgets\OfferJoiningAnalyticsWidget;
use App\Filament\Widgets\RecruiterLeaderboardWidget;
use App\Filament\Widgets\RecruitmentOverviewStats;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\RecruitmentRequisition;
use App\Models\Role;
use App\Models\User;
use App\Services\AI\Tools\IntelligenceTools\GetHiringHealthTool;
use App\Services\RecruitmentAnalyticsService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Exports\Models\Export;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.5 security findings: SEC-1 (widgets gated by the permission of their data), SEC-2
 * (compensation behind compensation.view), SEC-5 (a read that refreshes intelligence is audited).
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('a panel user without performance.view sees no team metric widget (SEC-1)', function (): void {
    $manager = Employee::factory()->create();
    Employee::factory()->reportingTo($manager)->create();
    $user = User::factory()->create(['employee_id' => $manager->id])->assignRole('employee');
    actingAs($user);

    expect(RecruitmentOverviewStats::canView())->toBeFalse()
        ->and(RecruiterLeaderboardWidget::canView())->toBeFalse()
        ->and(OfferJoiningAnalyticsWidget::canView())->toBeFalse();

    $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('Offer &amp; Joining', false);
});

test('every staff role keeps the widgets it saw before', function (): void {
    foreach (['vp_hr', 'manager', 'assistant_manager', 'recruiter'] as $role) {
        actingAs(User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole($role));

        expect(RecruitmentOverviewStats::canView())->toBeTrue("{$role} lost the overview");
    }
});

test('compensation.view is granted to exactly the roles that could already see offered CTC (D22)', function (): void {
    foreach (['chro', 'vp_hr', 'manager', 'assistant_manager', 'recruiter'] as $role) {
        expect(Role::byKey($role)->hasPermissionTo('compensation.view'))->toBeTrue("{$role} lost compensation.view");
    }

    expect(Role::byKey('employee')->hasPermissionTo('compensation.view'))->toBeFalse();
});

test('without compensation.view offered CTC is hidden in the offer table and the export (SEC-2)', function (): void {
    Role::findOrCreate('offers-no-pay')->syncPermissions(['offers.manage', 'candidates.viewAny']);
    $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('offers-no-pay');
    actingAs($user);
    $offer = Offer::factory()->create(['offered_ctc' => 1234567, 'status' => OfferStatus::Draft]);

    Livewire::test(ListOffers::class)->assertTableColumnHidden('offered_ctc');

    $export = new Export;
    $export->setRelation('user', $user);
    $row = (new OfferExporter($export, ['offer_code' => 'Offer', 'offered_ctc' => 'CTC'], []))($offer);

    expect($row)->toContain('Restricted')
        ->and(implode(',', array_map('strval', $row)))->not->toContain('1234567');

    $user->givePermissionTo('compensation.view');
    $export->setRelation('user', $user->fresh());

    expect((new OfferExporter($export, ['offer_code' => 'Offer', 'offered_ctc' => 'CTC'], []))($offer))->toContain('1234567.00');
});

test('the average offered CTC is withheld below the compensation group minimum (D22)', function (): void {
    foreach (range(1, 4) as $ignored) {
        Offer::factory()->create(['offered_ctc' => 800000, 'offer_date' => now()]);
    }

    expect(app(RecruitmentAnalyticsService::class)->offerAnalytics(now()->startOfMonth(), now()->endOfMonth())['average_offered_ctc'])->toBeNull();

    Offer::factory()->create(['offered_ctc' => 800000, 'offer_date' => now()]);

    expect(app(RecruitmentAnalyticsService::class)->offerAnalytics(now()->startOfMonth(), now()->endOfMonth())['average_offered_ctc'])->toBe(800000.0);
});

test('a Copilot read that refreshes Hiring Health records who asked and is audited (SEC-5)', function (): void {
    $user = User::factory()->create()->assignRole('chro');
    $requisition = RecruitmentRequisition::factory()->create();
    CandidateApplication::factory()->create(['requisition_id' => $requisition->id]);

    app(GetHiringHealthTool::class)->handle(['requisition_id' => $requisition->id], $user);

    expect(AuditLog::query()->where('action', 'hiring_health_refreshed')->latest('id')->first()?->changes['by_user_id'] ?? null)->toBe($user->id);
});
