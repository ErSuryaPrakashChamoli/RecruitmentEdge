<?php

use App\Enums\TalentPoolMemberSource;
use App\Enums\TalentPoolVisibility;
use App\Events\CandidateAddedToTalentPool;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Filament\Resources\TalentPools\Pages\ListTalentPools;
use App\Filament\Resources\TalentPools\Pages\ViewTalentPool;
use App\Filament\Resources\TalentPools\RelationManagers\MembersRelationManager;
use App\Filament\Resources\TalentPools\TalentPoolResource;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\TalentPool;
use App\Models\TalentPoolMembership;
use App\Models\User;
use App\Services\TalentPoolService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->pools = app(TalentPoolService::class);
});

function poolUser(string $role, ?Employee $reportsTo = null): User
{
    $employee = $reportsTo !== null ? Employee::factory()->reportingTo($reportsTo)->create() : Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $user->assignRole($role);

    return $user;
}

test('creating a pool slugs its name and makes the creator the owner', function (): void {
    $owner = Employee::factory()->create();

    $pool = $this->pools->create(['name' => 'Silver Medalists', 'visibility' => TalentPoolVisibility::Team->value], $owner);

    expect($pool->slug)->toBe('silver-medalists')
        ->and($pool->owner_id)->toBe($owner->id)
        ->and($pool->isActive())->toBeTrue();
});

test('adding candidates records membership metadata, the timeline, an audit row and an event', function (): void {
    Event::fake([CandidateAddedToTalentPool::class]);
    $pool = TalentPool::factory()->create();
    $candidate = Candidate::factory()->create();
    $actor = Employee::factory()->create();

    $result = $this->pools->addCandidates($pool, [$candidate->id], $actor, reason: 'Strong closer');

    $membership = TalentPoolMembership::query()->sole();

    expect($result)->toBe(['added' => 1, 'skipped' => 0])
        ->and($membership->added_by)->toBe($actor->id)
        ->and($membership->reason)->toBe('Strong closer')
        ->and($membership->source)->toBe(TalentPoolMemberSource::Manual)
        ->and($candidate->timelineEvents()->where('title', "Added to talent pool \"{$pool->name}\"")->exists())->toBeTrue()
        ->and(AuditLog::query()->where('auditable_type', TalentPoolMembership::class)->where('action', 'created')->exists())->toBeTrue();
    Event::assertDispatched(CandidateAddedToTalentPool::class);
});

test('a candidate already in a pool is skipped rather than added twice', function (): void {
    $pool = TalentPool::factory()->create();
    $candidate = Candidate::factory()->create();
    $this->pools->addCandidates($pool, [$candidate->id]);

    $result = $this->pools->addCandidates($pool, [$candidate->id, $candidate->id]);

    expect($result)->toBe(['added' => 0, 'skipped' => 1])
        ->and(TalentPoolMembership::query()->count())->toBe(1);
});

test('a candidate can belong to several pools without duplicating the candidate record', function (): void {
    $candidate = Candidate::factory()->create();
    [$sales, $leaders] = TalentPool::factory()->count(2)->create()->all();

    $this->pools->addCandidates($sales, [$candidate->id]);
    $this->pools->addCandidates($leaders, [$candidate->id]);

    expect($candidate->talentPools()->pluck('talent_pools.id')->sort()->values()->all())->toBe(collect([$sales->id, $leaders->id])->sort()->values()->all())
        ->and(Candidate::query()->count())->toBe(1);
});

test('removing a candidate keeps the membership history and re-adding reactivates the same row', function (): void {
    $pool = TalentPool::factory()->create();
    $candidate = Candidate::factory()->create();
    $this->pools->addCandidates($pool, [$candidate->id]);

    $removed = $this->pools->removeCandidates($pool, [$candidate->id], reason: 'Joined a competitor');
    $membership = TalentPoolMembership::query()->sole();

    expect($removed)->toBe(1)
        ->and($membership->removed_at)->not->toBeNull()
        ->and($membership->removal_reason)->toBe('Joined a competitor')
        ->and($pool->candidates()->count())->toBe(0)
        ->and(Candidate::query()->whereKey($candidate->id)->exists())->toBeTrue();

    $this->pools->addCandidates($pool, [$candidate->id]);

    expect(TalentPoolMembership::query()->sole()->removed_at)->toBeNull();
});

test('moving candidates adds them to the target and removes them from the source', function (): void {
    [$from, $to] = TalentPool::factory()->count(2)->create()->all();
    $candidate = Candidate::factory()->create();
    $this->pools->addCandidates($from, [$candidate->id]);

    $this->pools->moveCandidates($from, $to, [$candidate->id]);

    expect($from->candidates()->count())->toBe(0)
        ->and($to->candidates()->pluck('candidates.id')->all())->toBe([$candidate->id])
        ->and($to->activeMemberships()->sole()->source)->toBe(TalentPoolMemberSource::Moved);
});

test('an archived pool accepts no new candidates', function (): void {
    $this->pools->addCandidates(TalentPool::factory()->archived()->create(), [Candidate::factory()->create()->id]);
})->throws(DomainException::class, 'archived');

test('one request adds at most the per-request limit of candidates, and adds nothing beyond it (Phase 8.9, PF-88-05)', function (): void {
    $pool = TalentPool::factory()->create();

    expect(fn () => $this->pools->addCandidates($pool, range(1, TalentPoolService::MAX_CANDIDATES_PER_REQUEST + 1)))
        ->toThrow(DomainException::class, 'at most 500 candidates at a time')
        ->and(TalentPoolMembership::query()->count())->toBe(0);
});

test('pool visibility follows the hierarchy and the pool visibility setting', function (TalentPoolVisibility $visibility, string $viewer, bool $canSee): void {
    $vp = poolUser('vp_hr');
    $manager = poolUser('manager', $vp->employee);
    $recruiter = poolUser('recruiter', $manager->employee);
    $outsider = poolUser('recruiter');
    $pool = TalentPool::factory()->visibility($visibility)->create(['owner_id' => $manager->employee_id]);

    $user = ['vp' => $vp, 'owner' => $manager, 'team member' => $recruiter, 'outsider' => $outsider][$viewer];

    expect($user->can('view', $pool))->toBe($canSee)
        ->and(TalentPool::query()->visibleTo($user)->whereKey($pool->id)->exists())->toBe($canSee);
})->with([
    'private: owner sees it' => [TalentPoolVisibility::Private, 'owner', true],
    'private: owner\'s manager sees it' => [TalentPoolVisibility::Private, 'vp', true],
    'private: team member does not' => [TalentPoolVisibility::Private, 'team member', false],
    'team: team member sees it' => [TalentPoolVisibility::Team, 'team member', true],
    'team: another team does not' => [TalentPoolVisibility::Team, 'outsider', false],
    'organization: anyone with access' => [TalentPoolVisibility::Organization, 'outsider', true],
]);

test('only the owner chain with talent-pools.manage may edit or remove members', function (): void {
    $manager = poolUser('manager');
    $recruiter = poolUser('recruiter', $manager->employee);
    $otherManager = poolUser('manager');
    $pool = TalentPool::factory()->visibility(TalentPoolVisibility::Organization)->create(['owner_id' => $manager->employee_id]);

    expect($manager->can('update', $pool))->toBeTrue()
        ->and($otherManager->can('update', $pool))->toBeFalse()
        ->and($recruiter->can('removeMembers', $pool))->toBeFalse()
        ->and($recruiter->can('addMembers', $pool))->toBeTrue();
});

test('a user outside the hierarchy cannot open a private pool directly', function (): void {
    $pool = TalentPool::factory()->visibility(TalentPoolVisibility::Private)->create();
    actingAs(poolUser('manager'));

    $this->get(TalentPoolResource::getUrl('view', ['record' => $pool]))->assertNotFound();
});

test('the pool list only shows pools the user can see', function (): void {
    $manager = poolUser('manager');
    actingAs($manager);
    $own = TalentPool::factory()->visibility(TalentPoolVisibility::Private)->create(['owner_id' => $manager->employee_id]);
    $hidden = TalentPool::factory()->visibility(TalentPoolVisibility::Private)->create();

    Livewire::test(ListTalentPools::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$hidden]);
});

test('pool members are limited to candidates the viewer may see', function (): void {
    $manager = poolUser('manager');
    actingAs($manager);
    $pool = TalentPool::factory()->visibility(TalentPoolVisibility::Organization)->create(['owner_id' => $manager->employee_id]);
    $mine = CandidateApplication::factory()->create(['recruiter_id' => $manager->employee_id])->candidate;
    $theirs = Candidate::factory()->create();
    $this->pools->addCandidates($pool, [$mine->id, $theirs->id]);

    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $pool, 'pageClass' => ViewTalentPool::class])
        ->assertCanSeeTableRecords(TalentPoolMembership::query()->where('candidate_id', $mine->id)->get())
        ->assertCanNotSeeTableRecords(TalentPoolMembership::query()->where('candidate_id', $theirs->id)->get());
});

test('adding candidates from the pool refuses candidates outside the user\'s scope', function (): void {
    $manager = poolUser('manager');
    actingAs($manager);
    $pool = TalentPool::factory()->create(['owner_id' => $manager->employee_id]);
    $mine = CandidateApplication::factory()->create(['recruiter_id' => $manager->employee_id])->candidate;
    $theirs = Candidate::factory()->create();

    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $pool, 'pageClass' => ViewTalentPool::class])
        ->callAction(TestAction::make('addCandidates')->table(), ['candidate_ids' => [$mine->id, $theirs->id]])
        ->assertHasFormErrors(['candidate_ids.1']);

    expect($pool->candidates()->count())->toBe(0);

    Livewire::test(MembersRelationManager::class, ['ownerRecord' => $pool, 'pageClass' => ViewTalentPool::class])
        ->callAction(TestAction::make('addCandidates')->table(), ['candidate_ids' => [$mine->id]])
        ->assertHasNoFormErrors();

    expect($pool->candidates()->pluck('candidates.id')->all())->toBe([$mine->id]);
});

test('candidates can be bulk added to a pool from the candidates list', function (): void {
    $user = poolUser('chro');
    actingAs($user);
    $pool = TalentPool::factory()->create(['owner_id' => $user->employee_id]);
    $candidates = Candidate::factory()->count(3)->create();

    Livewire::test(ListCandidates::class)
        ->selectTableRecords($candidates)
        ->callAction(TestAction::make('addToTalentPool')->table()->bulk(), ['talent_pool_id' => $pool->id]);

    expect($pool->candidates()->count())->toBe(3)
        ->and($pool->activeMemberships()->first()->source)->toBe(TalentPoolMemberSource::Bulk);
});
