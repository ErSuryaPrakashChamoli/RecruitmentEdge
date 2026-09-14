<?php

use App\Enums\JoiningStatus;
use App\Enums\Priority;
use App\Enums\RequisitionStatus;
use App\Filament\Pages\RecruitmentReports;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\RecruitmentCost;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the recruitment reports page renders successfully with no data', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');

    actingAs($user)
        ->get('/admin/recruitment-reports')
        ->assertSuccessful()
        ->assertSee('No open or on-hold requisitions');
});

test('the recruitment reports page renders successfully for a scoped recruiter', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');

    actingAs($user)->get('/admin/recruitment-reports')->assertSuccessful();
});

test('a user with reports.export can export the funnel and vacancy ageing as CSV', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(RecruitmentReports::class)
        ->call('exportFunnel')
        ->assertFileDownloaded('recruitment-funnel.csv');

    Livewire::test(RecruitmentReports::class)
        ->call('exportVacancyAgeing')
        ->assertFileDownloaded('vacancy-ageing.csv');

    Livewire::test(RecruitmentReports::class)
        ->call('exportSourceRoi')
        ->assertFileDownloaded('source-roi.csv');
});

test('a user without reports.export cannot export', function (): void {
    Role::findOrCreate('no-export')->syncPermissions(['performance.view']);

    $user = User::factory()->create();
    $user->assignRole('no-export');
    actingAs($user);

    Livewire::test(RecruitmentReports::class)
        ->call('exportFunnel')
        ->assertStatus(403);
});

test('cost per hire on the reports page honours the source filter', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    $naukri = CandidateSource::factory()->create();
    $referral = CandidateSource::factory()->create();

    RecruitmentCost::factory()->create(['amount' => 10000, 'incurred_on' => now(), 'source_id' => $naukri->id]);
    RecruitmentCost::factory()->create(['amount' => 6000, 'incurred_on' => now(), 'source_id' => $referral->id]);

    foreach ([$naukri, $referral] as $source) {
        CandidateJoining::factory()->create([
            'candidate_application_id' => CandidateApplication::factory()->create([
                'candidate_id' => Candidate::factory()->create(['source_id' => $source->id])->id,
            ])->id,
            'status' => JoiningStatus::Joined,
            'actual_doj' => now(),
        ]);
    }

    $page = Livewire::test(RecruitmentReports::class);

    expect($page->instance()->getCostPerHire())->toBe(8000.0);

    $page->set('data.source_id', $naukri->id);

    expect($page->instance()->getCostPerHire())->toBe(10000.0);
});

test('cost per hire on the reports page is scoped to the viewer hierarchy', function (): void {
    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();

    $teamRequisition = RecruitmentRequisition::factory()->create(['manager_id' => $manager->id]);
    RecruitmentCost::factory()->create(['amount' => 4000, 'incurred_on' => now(), 'requisition_id' => $teamRequisition->id]);
    RecruitmentCost::factory()->create(['amount' => 90000, 'incurred_on' => now()]);

    CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now(),
    ]);
    CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $outsider->id])->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now(),
    ]);

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');
    actingAs($user);

    expect(Livewire::test(RecruitmentReports::class)->instance()->getCostPerHire())->toBe(4000.0);
});

test('the vacancy ageing table shows a priority column', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');

    RecruitmentRequisition::factory()->create([
        'status' => RequisitionStatus::Open,
        'opening_date' => now()->subDays(60),
        'priority' => Priority::Urgent,
    ]);

    actingAs($user)
        ->get('/admin/recruitment-reports')
        ->assertSuccessful()
        ->assertSee('Priority')
        ->assertSee('Urgent');
});
