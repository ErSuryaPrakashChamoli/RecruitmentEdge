<?php

use App\Enums\InterviewStatus;
use App\Filament\Pages\Pipeline;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Livewire\CommandPalette;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.9 (P89-PERF-002/003/010/013): faster query paths with unchanged results — candidate
 * visibility, exact identifier search, the audit date filter and the pipeline summary/options.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->managerEmployee = Employee::factory()->create();
    $this->teamRecruiter = Employee::factory()->create(['reports_to_id' => $this->managerEmployee->id]);
    $this->manager = User::factory()->create(['employee_id' => $this->managerEmployee->id])->assignRole('manager');

    $this->teamCandidate = Candidate::factory()->create(['email' => 'asha.k+jobs@gmail.com', 'mobile' => '+91 98765 43210']);
    CandidateApplication::factory()->create(['candidate_id' => $this->teamCandidate->id, 'recruiter_id' => $this->teamRecruiter->id]);
    $this->createdByManager = Candidate::factory()->create(['created_by' => $this->managerEmployee->id]);
    $this->otherCandidate = Candidate::factory()->create(['email' => 'ravi@example.com', 'mobile' => '9123456780']);
    CandidateApplication::factory()->create(['candidate_id' => $this->otherCandidate->id]);
    $this->onlyDeletedApplication = Candidate::factory()->create();
    CandidateApplication::factory()->create(['candidate_id' => $this->onlyDeletedApplication->id, 'recruiter_id' => $this->teamRecruiter->id])->delete();
});

test('candidate visibility is unchanged: team applications or own creations, never deleted applications', function (): void {
    expect(Candidate::query()->visibleTo($this->manager)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->teamCandidate->id, $this->createdByManager->id])->sort()->values()->all())
        ->and(Candidate::query()->visibleTo(User::factory()->create()->assignRole('chro'))->count())->toBe(Candidate::query()->count());
});

test('a complete email, mobile number or code finds the candidate exactly — inside the hierarchy only', function (string $term): void {
    actingAs($this->manager);

    Livewire::test(ListCandidates::class)
        ->searchTable($term)
        ->assertCanSeeTableRecords([$this->teamCandidate])
        ->assertCanNotSeeTableRecords([$this->otherCandidate, $this->createdByManager]);

    expect(CandidateResource::getGlobalSearchResults($term)->pluck('title')->all())->toBe([$this->teamCandidate->full_name]);
})->with([
    'stored email' => ['asha.k+jobs@gmail.com'],
    'same inbox, other spelling' => ['ASHAK@gmail.com'],
    'mobile with country code' => ['+91-98765-43210'],
    'mobile, national digits' => ['9876543210'],
    'candidate code' => [fn () => strtolower(Candidate::query()->where('email', 'asha.k+jobs@gmail.com')->value('candidate_code'))],
]);

test('another team candidate is never found by exact identifier', function (): void {
    actingAs($this->manager);

    Livewire::test(ListCandidates::class)->searchTable('ravi@example.com')->assertCountTableRecords(0);
    expect(CandidateResource::getGlobalSearchResults('9123456780'))->toBeEmpty();
});

test('anything else keeps the substring search', function (): void {
    actingAs($this->manager);
    $partial = substr($this->teamCandidate->full_name, 1, 4);

    Livewire::test(ListCandidates::class)->searchTable($partial)->assertCanSeeTableRecords([$this->teamCandidate]);
    Livewire::test(ListCandidates::class)->searchTable('asha.k')->assertCanSeeTableRecords([$this->teamCandidate]);
    expect(collect(Livewire::test(CommandPalette::class)->set('search', 'asha.k+jobs@gmail.com')->get('results'))->pluck('title')->all())->toContain($this->teamCandidate->full_name)
        ->and(collect(Livewire::test(CommandPalette::class)->set('search', 'ravi@example.com')->get('results'))->pluck('title')->all())->not->toContain($this->otherCandidate->full_name);
});

test('the audit date filter keeps whole days', function (): void {
    actingAs(User::factory()->create()->assignRole('chro'));
    AuditLog::query()->delete();
    $atEndOfDay = AuditLog::query()->forceCreate(['action' => 'updated', 'auditable_type' => Candidate::class, 'auditable_id' => 1, 'created_at' => '2026-03-10 23:59:59']);
    $nextDay = AuditLog::query()->forceCreate(['action' => 'updated', 'auditable_type' => Candidate::class, 'auditable_id' => 2, 'created_at' => '2026-03-11 00:00:00']);
    $dayBefore = AuditLog::query()->forceCreate(['action' => 'updated', 'auditable_type' => Candidate::class, 'auditable_id' => 3, 'created_at' => '2026-03-09 23:59:59']);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('created_at', ['from' => '2026-03-10', 'until' => '2026-03-10'])
        ->assertCanSeeTableRecords([$atEndOfDay])
        ->assertCanNotSeeTableRecords([$nextDay, $dayBefore]);
});

test('the pipeline lists the recruiters behind the scoped applications and today\'s interviews', function (): void {
    actingAs($this->manager);
    $application = CandidateApplication::query()->where('candidate_id', $this->teamCandidate->id)->sole();
    lifecycleFixture(fn () => Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Scheduled, 'scheduled_at' => today()->setTime(23, 59)]));
    lifecycleFixture(fn () => Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Scheduled, 'scheduled_at' => today()->addDay()]));

    $page = Livewire::test(Pipeline::class)->instance();

    expect(collect($page->recruiterOptions())->pluck('value')->all())->toBe([$this->teamRecruiter->id])
        ->and($page->getSummary()['interviews_today'])->toBe(1);
});
