<?php

use App\Enums\ApplicationStatus;
use App\Enums\IncentiveCalculationStatus;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Models\AiConversation;
use App\Models\AiKnowledgeArticle;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateDuplicateMatch;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Interviewer;
use App\Models\Offer;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruiterPerformanceSnapshot;
use App\Models\RecruitmentCost;
use App\Models\RecruitmentDailyActivity;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use Database\Seeders\Demo\DemoCatalog;
use Database\Seeders\Demo\DemoSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

beforeEach(function (): void {
    // DEMO_TEST_SCALE=1 replays the exact full demo (the story is seeded deterministically).
    config(['demo.enabled' => true, 'demo.scale' => (float) env('DEMO_TEST_SCALE', 0.12)]);
});

/**
 * @return list<User>
 */
function demoLogins(): array
{
    return array_map(
        fn (array $login): User => User::query()->where('email', DemoCatalog::personEmail($login['person']))->firstOrFail(),
        DemoCatalog::LOGINS,
    );
}

function assertPageRenders(User $user, string $url): void
{
    $status = get($url)->status();

    expect($status)->toBe(200, "{$user->email} got HTTP {$status} on {$url}");
}

test('the demo seeds every module with a coherent story and working logins', function (): void {
    // As on a real demo installation: notifications must still be delivered during the seed.
    config(['queue.default' => 'database']);

    $this->seed(DemoSeeder::class);

    expect(RecruitmentRequisition::query()->distinct()->pluck('status')->map->value->sort()->values()->all())
        ->toEqual(collect(RequisitionStatus::cases())->map->value->sort()->values()->all());

    expect(CandidateApplication::query()->where('status', ApplicationStatus::Active)->exists())->toBeTrue()
        ->and(CandidateApplication::query()->where('status', ApplicationStatus::Rejected)->exists())->toBeTrue()
        ->and(CandidateApplication::query()->where('status', ApplicationStatus::Dropout)->exists())->toBeTrue()
        ->and(Interview::query()->where('status', InterviewStatus::Completed)->has('feedback')->exists())->toBeTrue()
        ->and(Interview::query()->where('scheduled_at', '>', now())->exists())->toBeTrue()
        ->and(Offer::query()->where('status', OfferStatus::Accepted)->exists())->toBeTrue()
        ->and(CandidateJoining::query()->where('status', JoiningStatus::Joined)->has('documents')->exists())->toBeTrue()
        ->and(Employee::query()->whereNotNull('candidate_id')->exists())->toBeTrue()
        ->and(RecruiterIncentiveCalculation::query()->where('status', IncentiveCalculationStatus::Paid)->has('payments')->exists())->toBeTrue()
        ->and(RecruiterPerformanceSnapshot::query()->exists())->toBeTrue()
        ->and(RecruitmentCost::query()->exists())->toBeTrue()
        ->and(RecruitmentFollowup::query()->where('status', 'pending')->exists())->toBeTrue()
        ->and(RecruitmentDailyActivity::query()->where('activity_datetime', '>=', today())->exists())->toBeTrue()
        ->and(CandidateDuplicateMatch::query()->exists())->toBeTrue()
        ->and(Interviewer::query()->count())->toBe(count(DemoCatalog::INTERVIEWERS))
        ->and(AiKnowledgeArticle::query()->count())->toBe(count(DemoCatalog::KNOWLEDGE_ARTICLES))
        ->and(AiConversation::query()->exists())->toBeTrue()
        ->and(AuditLog::query()->exists())->toBeTrue();

    // Nothing in the story happens after "now".
    expect(CandidateApplication::query()->where('created_at', '>', now())->exists())->toBeFalse()
        ->and(Offer::query()->where('offer_date', '>', today())->exists())->toBeFalse();

    foreach (demoLogins() as $user) {
        expect(Hash::check(config('demo.password'), $user->password))->toBeTrue()
            ->and($user->roles)->not->toBeEmpty()
            ->and($user->employee)->not->toBeNull()
            ->and($user->unreadNotifications()->count())->toBeLessThanOrEqual(8);
    }

    // Notifications carry their moment in the story, not the moment a queue worker ran.
    expect(DB::table('notifications')->where('created_at', '<', now()->subDays(30))->exists())->toBeTrue();
});

test('every page, resource, relation manager and widget renders for every demo login', function (): void {
    $this->seed(DemoSeeder::class);

    $panel = Filament::getPanel('admin');

    foreach (demoLogins() as $user) {
        // A fresh session per login, as AuthenticateSession ties a session to one user's password hash.
        $this->flushSession();
        actingAs($user);
        $visited = 0;

        foreach ($panel->getPages() as $page) {
            if ($page::canAccess()) {
                assertPageRenders($user, $page::getUrl());
                $visited++;
            }
        }

        /** @var class-string<resource> $resource */
        foreach ($panel->getResources() as $resource) {
            if (! $resource::canAccess()) {
                continue;
            }

            assertPageRenders($user, $resource::getUrl('index'));
            $visited++;

            if ($resource::hasPage('create') && $resource::canCreate()) {
                assertPageRenders($user, $resource::getUrl('create'));
            }

            $record = $resource::getEloquentQuery()->latest($resource::getModel()::make()->getKeyName())->first();

            if ($record === null) {
                continue;
            }

            $pageClass = null;

            foreach (['view', 'edit'] as $page) {
                if ($resource::hasPage($page) && $resource::{'can'.ucfirst($page)}($record)) {
                    assertPageRenders($user, $resource::getUrl($page, ['record' => $record]));
                    $pageClass ??= $resource::getPages()[$page]->getPage();
                }
            }

            if ($pageClass === null) {
                continue;
            }

            foreach ($resource::getRelations() as $relationManager) {
                if (is_string($relationManager) && $relationManager::canViewForRecord($record, $pageClass)) {
                    Livewire::test($relationManager, ['ownerRecord' => $record, 'pageClass' => $pageClass])->assertSuccessful();
                }
            }
        }

        foreach ($panel->getWidgets() as $widget) {
            if ($widget::canView()) {
                Livewire::test($widget)->assertSuccessful();
            }
        }

        expect($visited)->toBeGreaterThan(5, "{$user->email} could open almost nothing.");
    }
});

test('demo setup refuses to wipe an installation that is not in demo mode', function (): void {
    config(['demo.enabled' => false]);
    $user = User::factory()->create();

    artisan('demo:setup', ['--force' => true])->assertFailed();

    expect($user->fresh())->not->toBeNull();
});

test('the login page lists the demo accounts only in demo mode', function (): void {
    get('/admin/login')->assertOk()->assertSee('recruiter@example.com')->assertSee('Sign in as');

    config(['demo.enabled' => false]);

    get('/admin/login')->assertOk()->assertDontSee('recruiter@example.com');
});
