<?php

namespace App\Livewire;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\IncentiveDashboard;
use App\Filament\Pages\Pipeline;
use App\Filament\Pages\RecruitmentReports;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Filament\Resources\Offers\OfferResource;
use App\Filament\Resources\RecruiterPerformanceSnapshots\RecruiterPerformanceSnapshotResource;
use App\Filament\Resources\RecruitmentFollowups\RecruitmentFollowupResource;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A single Ctrl/Cmd+K surface combining record search and action-style commands (Section 10) —
 * genuinely new architecture (no render-hook/Livewire precedent existed in this codebase before).
 * Native Filament global search stays exactly as-is at the data layer: search results here reuse
 * the same getGloballySearchableAttributes()/getGlobalSearchEloquentQuery() config already defined
 * on CandidateResource/CandidateApplicationResource, rather than redefining search criteria — those
 * queries already inherit hierarchy scoping from the resource's own getEloquentQuery().
 */
class CommandPalette extends Component
{
    public bool $isOpen = false;

    public string $search = '';

    #[On('close-command-palette')]
    public function close(): void
    {
        $this->isOpen = false;
        $this->search = '';
    }

    public function open(): void
    {
        $this->isOpen = true;
    }

    /**
     * @return array<int, array{title: string, url: string, group: string}>
     */
    public function getResultsProperty(): array
    {
        if (blank($this->search)) {
            return [];
        }

        return [
            ...$this->searchResource(CandidateResource::class, 'Candidates'),
            ...$this->searchResource(CandidateApplicationResource::class, 'Applications'),
        ];
    }

    /**
     * @return array<int, array{label: string, url: string}>
     */
    public function getCommandsProperty(): array
    {
        /** @var Authenticatable|null $user */
        $user = auth()->user();

        $commands = collect([
            ['label' => 'Create Candidate', 'permission' => 'candidates.create', 'url' => CandidateResource::getUrl('create')],
            ['label' => 'Create Requisition', 'permission' => 'requisitions.create', 'url' => RecruitmentRequisitionResource::getUrl('create')],
            ['label' => 'Schedule Interview', 'permission' => 'interviews.manage', 'url' => InterviewResource::getUrl('create')],
            ['label' => 'Add Follow-up', 'permission' => 'followups.manage', 'url' => RecruitmentFollowupResource::getUrl('create')],
            ['label' => 'Create Offer', 'permission' => 'offers.manage', 'url' => OfferResource::getUrl('create')],
            ['label' => 'Update Joining', 'permission' => 'joining.confirm', 'url' => CandidateJoiningResource::getUrl('index')],
            ['label' => 'Open Candidate Pipeline', 'permission' => 'candidates.viewAny', 'url' => Pipeline::getUrl()],
            ['label' => 'Open Recruiter Performance', 'permission' => 'performance.view', 'url' => RecruiterPerformanceSnapshotResource::getUrl('index')],
            ['label' => 'Open Incentive Dashboard', 'permission' => 'incentives.view', 'url' => IncentiveDashboard::getUrl()],
            ['label' => 'Open Reports', 'permission' => 'performance.view', 'url' => RecruitmentReports::getUrl()],
            ['label' => 'Open Dashboard', 'permission' => null, 'url' => Dashboard::getUrl()],
        ])
            ->filter(fn (array $command): bool => $command['permission'] === null || (bool) $user?->can($command['permission']))
            ->when(
                filled($this->search),
                fn (Collection $c) => $c->filter(fn (array $command) => str_contains(strtolower($command['label']), strtolower($this->search))),
            )
            ->values();

        return $commands->all();
    }

    /**
     * @param  class-string  $resourceClass
     * @return array<int, array{title: string, url: string, group: string}>
     */
    private function searchResource(string $resourceClass, string $group): array
    {
        $attributes = $resourceClass::getGloballySearchableAttributes();
        $term = $this->search;

        $records = $resourceClass::getGlobalSearchEloquentQuery()
            ->where(function (Builder $query) use ($attributes, $term): void {
                foreach ($attributes as $attribute) {
                    $query->orWhere($attribute, 'like', "%{$term}%");
                }
            })
            ->limit(5)
            ->get();

        return $records
            ->map(fn (Model $record) => [
                'title' => $resourceClass::getGlobalSearchResultTitle($record),
                'url' => $resourceClass::getUrl($resourceClass::hasPage('view') ? 'view' : 'edit', ['record' => $record]),
                'group' => $group,
            ])
            ->all();
    }

    public function render()
    {
        return view('livewire.command-palette', [
            'results' => $this->results,
            'commands' => $this->commands,
        ]);
    }
}
