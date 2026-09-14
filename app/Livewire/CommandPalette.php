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
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;
use UnitEnum;

/**
 * A single Ctrl/Cmd+K surface combining record search, jump-to navigation, and action-style
 * commands (Section 10).
 *
 * - Record search reuses each resource's own global-search config
 *   (getGlobalSearchEloquentQuery()/getGloballySearchableAttributes()/getGlobalSearchResultTitle()/
 *   getGlobalSearchResultUrl()) so results inherit hierarchy scoping from the resource's
 *   getEloquentQuery(). Dotted attributes (e.g. `candidate.full_name`) are matched through
 *   whereHas on the relation, exactly like Filament's native global search.
 * - Navigation is derived from the panel's registered resources and pages, filtered by each one's
 *   own canAccess(), so it never lists a destination the user would get a 403 on.
 */
class CommandPalette extends Component
{
    private const int RESULTS_PER_RESOURCE = 5;

    /**
     * Resources searched from the palette, with the group label shown. What is searched and how a
     * result is titled comes entirely from each resource's own global-search config.
     *
     * @var array<class-string<resource>, string>
     */
    private const array SEARCHABLE_RESOURCES = [
        CandidateResource::class => 'Candidates',
        CandidateApplicationResource::class => 'Applications',
        RecruitmentRequisitionResource::class => 'Requisitions',
        OfferResource::class => 'Offers',
    ];

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

        return collect(self::SEARCHABLE_RESOURCES)
            ->flatMap(fn (string $group, string $resourceClass) => $this->searchResource($resourceClass, $group))
            ->values()
            ->all();
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
                fn (Collection $c) => $c->filter(fn (array $command) => Str::contains($command['label'], $this->search, ignoreCase: true)),
            )
            ->map(fn (array $command) => ['label' => $command['label'], 'url' => $command['url']])
            ->values();

        return $commands->all();
    }

    /**
     * Jump-to entries for every resource list and navigable page in the current panel that the
     * user can access.
     *
     * @return array<int, array{label: string, url: string, group: string}>
     */
    public function getNavigationProperty(): array
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        if ($panel === null) {
            return [];
        }

        $resources = collect($panel->getResources())
            ->filter(fn (string $resource): bool => $resource::hasPage('index') && $resource::shouldRegisterNavigation() && $resource::canAccess())
            ->map(fn (string $resource): ?array => $this->navigationEntry(
                fn () => $resource::getNavigationLabel(),
                fn () => $resource::getUrl('index'),
                fn () => $resource::getNavigationGroup(),
            ));

        $pages = collect($panel->getPages())
            ->filter(fn (string $page): bool => is_subclass_of($page, Page::class) && $page::shouldRegisterNavigation() && $page::canAccess())
            ->map(fn (string $page): ?array => $this->navigationEntry(
                fn () => $page::getNavigationLabel(),
                fn () => $page::getUrl(),
                fn () => $page::getNavigationGroup(),
            ));

        return $pages->merge($resources)
            ->filter()
            ->unique('url')
            ->when(
                filled($this->search),
                fn (Collection $c) => $c->filter(fn (array $entry) => Str::contains($entry['label'], $this->search, ignoreCase: true)),
            )
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @param  class-string<resource>  $resourceClass
     * @return array<int, array{title: string, url: string, group: string}>
     */
    private function searchResource(string $resourceClass, string $group): array
    {
        if (! $resourceClass::canViewAny()) {
            return [];
        }

        $attributes = collect($resourceClass::getGloballySearchableAttributes())
            ->flatten()
            ->unique()
            ->values()
            ->all();

        if ($attributes === []) {
            return [];
        }

        $term = $this->search;

        $records = $resourceClass::getGlobalSearchEloquentQuery()
            ->where(function (Builder $query) use ($attributes, $term): void {
                foreach ($attributes as $attribute) {
                    $this->applySearchConstraint($query, $attribute, $term);
                }
            })
            ->limit(self::RESULTS_PER_RESOURCE)
            ->get();

        return $records
            ->map(function (Model $record) use ($resourceClass, $group): ?array {
                $url = $resourceClass::getGlobalSearchResultUrl($record);

                if ($url === null) {
                    return null;
                }

                return [
                    'title' => (string) $resourceClass::getGlobalSearchResultTitle($record),
                    'url' => $url,
                    'group' => $group,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * OR-matches one attribute; a dotted attribute (`relation.nested.column`) is matched through
     * whereHas on the relation path rather than as a (non-existent) column.
     */
    private function applySearchConstraint(Builder $query, string $attribute, string $term): void
    {
        if (! str_contains($attribute, '.')) {
            $query->orWhere($query->qualifyColumn($attribute), 'like', "%{$term}%");

            return;
        }

        $relation = Str::beforeLast($attribute, '.');
        $column = Str::afterLast($attribute, '.');

        $query->orWhereHas($relation, fn (Builder $related) => $related->where($related->qualifyColumn($column), 'like', "%{$term}%"));
    }

    /**
     * @param  callable(): string  $label
     * @param  callable(): string  $url
     * @param  callable(): mixed  $group
     * @return array{label: string, url: string, group: string}|null
     */
    private function navigationEntry(callable $label, callable $url, callable $group): ?array
    {
        try {
            $groupValue = $group();

            return [
                'label' => (string) $label(),
                'url' => $url(),
                'group' => $groupValue instanceof UnitEnum ? $groupValue->name : (string) ($groupValue ?? 'General'),
            ];
        } catch (Throwable) {
            // A page/resource whose URL needs route parameters can't be jumped to directly.
            return null;
        }
    }

    public function render(): View
    {
        return view('livewire.command-palette', [
            'results' => $this->isOpen ? $this->results : [],
            'navigation' => $this->isOpen ? $this->navigation : [],
            'commands' => $this->isOpen ? $this->commands : [],
        ]);
    }
}
