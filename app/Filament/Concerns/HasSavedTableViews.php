<?php

namespace App\Filament\Concerns;

use App\Models\SavedTableView;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reusable saved-view infrastructure (Section 39) for any Filament List page's table — Filament v5
 * has no native equivalent. Persists only Filament's own table filter/sort/search state
 * ($this->tableFilters/$this->tableSort/$this->tableSearch — the public properties HasFilters/
 * CanSortRecords/CanSearchRecords already declare), so a saved view can never surface data the
 * page's normal (hierarchy-scoped) query wouldn't already return for that user — it replays input,
 * not a raw query. Strictly per-user (scoped by user_id); a `resource` string (the List page's own
 * FQCN) keeps one user's views for different tables from colliding.
 */
trait HasSavedTableViews
{
    protected function savedTableViewsQuery(): Builder
    {
        return SavedTableView::query()
            ->where('user_id', auth()->id())
            ->where('resource', static::class);
    }

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function savedTableViewActions(): array
    {
        return [
            ActionGroup::make([
                $this->saveTableViewAction(),
                $this->loadTableViewAction(),
                $this->renameTableViewAction(),
                $this->deleteTableViewAction(),
            ])
                ->label('Saved Views')
                ->icon('heroicon-o-bookmark')
                ->color('gray'),
        ];
    }

    private function saveTableViewAction(): Action
    {
        return Action::make('saveTableView')
            ->label('Save Current View')
            ->icon('heroicon-o-plus-circle')
            ->schema([
                TextInput::make('name')->required()->maxLength(255),
                Checkbox::make('is_default')->label('Set as my default view for this list'),
            ])
            ->action(function (array $data): void {
                if ($data['is_default']) {
                    $this->savedTableViewsQuery()->update(['is_default' => false]);
                }

                $this->savedTableViewsQuery()->updateOrCreate(
                    ['name' => $data['name']],
                    [
                        'user_id' => auth()->id(),
                        'resource' => static::class,
                        'filters' => $this->tableFilters,
                        'sort' => $this->tableSort,
                        'search' => $this->tableSearch,
                        'is_default' => (bool) $data['is_default'],
                    ],
                );

                Notification::make()->title('View saved')->success()->send();
            });
    }

    private function loadTableViewAction(): Action
    {
        return Action::make('loadTableView')
            ->label('Load View')
            ->icon('heroicon-o-folder-open')
            ->visible(fn (): bool => $this->savedTableViewsQuery()->exists())
            ->schema([
                Select::make('view_id')
                    ->label('View')
                    ->options(fn () => $this->savedTableViewsQuery()->pluck('name', 'id'))
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->applySavedTableView($this->savedTableViewsQuery()->findOrFail($data['view_id']));
            });
    }

    private function renameTableViewAction(): Action
    {
        return Action::make('renameTableView')
            ->label('Rename View')
            ->icon('heroicon-o-pencil')
            ->visible(fn (): bool => $this->savedTableViewsQuery()->exists())
            ->schema([
                Select::make('view_id')
                    ->label('View')
                    ->options(fn () => $this->savedTableViewsQuery()->pluck('name', 'id'))
                    ->required()
                    ->live(),
                TextInput::make('name')->required()->maxLength(255),
            ])
            ->action(function (array $data): void {
                $this->savedTableViewsQuery()->findOrFail($data['view_id'])->update(['name' => $data['name']]);
                Notification::make()->title('View renamed')->success()->send();
            });
    }

    private function deleteTableViewAction(): Action
    {
        return Action::make('deleteTableView')
            ->label('Delete View')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => $this->savedTableViewsQuery()->exists())
            ->schema([
                Select::make('view_id')
                    ->label('View')
                    ->options(fn () => $this->savedTableViewsQuery()->pluck('name', 'id'))
                    ->required(),
            ])
            ->requiresConfirmation()
            ->action(function (array $data): void {
                $this->savedTableViewsQuery()->findOrFail($data['view_id'])->delete();
                Notification::make()->title('View deleted')->success()->send();
            });
    }

    private function applySavedTableView(SavedTableView $view): void
    {
        $this->tableFilters = $view->filters;
        $this->tableSearch = $view->search ?? '';
        $this->tableSort = $view->sort;
        $this->resetPage();
    }
}
