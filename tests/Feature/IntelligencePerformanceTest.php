<?php

use App\Enums\RequisitionStatus;
use App\Filament\Pages\IntelligenceOverview;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Intelligence\HiringHealthService;
use App\Services\Intelligence\TalentRediscoveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->recruiter = Employee::factory()->create();
    $this->user = User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');
});

function intelligenceQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('rediscovery does not issue queries per scanned candidate', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['skills' => ['Rust']]);
    $seed = fn (int $n) => Candidate::factory()->count($n)->create(['skills' => ['Java'], 'total_experience' => 3])
        ->each(fn (Candidate $c) => CandidateApplication::factory()->create(['candidate_id' => $c->id, 'recruiter_id' => $this->recruiter->id]));
    $service = app(TalentRediscoveryService::class);

    $seed(3);
    $service->run($requisition, $this->user);
    $small = intelligenceQueries(fn () => $service->run($requisition, $this->user));
    $seed(20);
    $large = intelligenceQueries(fn () => $service->run($requisition, $this->user));

    expect($large)->toBe($small);
});

test('the overview page reads persisted intelligence with a constant number of queries', function (): void {
    $seed = function (int $n): void {
        foreach (range(1, $n) as $i) {
            $requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);
            $requisition->recruiters()->attach($this->recruiter->id);
            app(HiringHealthService::class)->refresh($requisition);
        }
    };
    $this->actingAs($this->user);
    $page = new IntelligenceOverview;

    $seed(2);
    $page->requisitions();
    $small = intelligenceQueries(fn () => $page->distribution($page->requisitions()));
    $seed(10);
    $large = intelligenceQueries(fn () => $page->distribution($page->requisitions()));

    expect($large)->toBe($small);
});
