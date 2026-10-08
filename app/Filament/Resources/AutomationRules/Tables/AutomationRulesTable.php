<?php

namespace App\Filament\Resources\AutomationRules\Tables;

use App\Enums\AutomationRuleStatus;
use App\Filament\Resources\AutomationRules\AutomationRuleActions;
use App\Models\AutomationRule;
use App\Services\Automation\AutomationEventRegistry;
use App\Services\Automation\AutomationScopeResolver;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AutomationRulesTable
{
    public static function configure(Table $table): Table
    {
        $events = app(AutomationEventRegistry::class);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('executions')->withMax('executions', 'created_at'))
            ->defaultSort('priority')
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (AutomationRule $record) => $record->template_key !== null ? 'From template: '.str_replace('_', ' ', $record->template_key) : null)->wrap(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (AutomationRuleStatus $state) => $state->label())->color(fn (AutomationRuleStatus $state) => $state->color()),
                TextColumn::make('trigger')->formatStateUsing(fn (string $state) => $events->find($state)?->label ?? $state)->wrap(),
                TextColumn::make('scope_type')->label('Scope')->state(fn (AutomationRule $record) => app(AutomationScopeResolver::class)->describe($record)),
                TextColumn::make('version')->label('Ver.')->prefix('v'),
                TextColumn::make('executions_count')->label('Runs')->numeric(),
                TextColumn::make('executions_max_created_at')->label('Last run')->since()->placeholder('Never'),
                TextColumn::make('priority')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(AutomationRuleStatus::options()),
                SelectFilter::make('trigger')->options(collect($events->options())->collapse()->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    AutomationRuleActions::dryRun(),
                    AutomationRuleActions::activate(),
                    AutomationRuleActions::pause(),
                    AutomationRuleActions::duplicate(),
                    AutomationRuleActions::archive(),
                ]),
            ])
            ->emptyStateHeading('No automation rules yet')
            ->emptyStateDescription('Start from a template or build a rule: WHEN something happens, IF conditions hold, THEN act — and escalate if it stays unresolved.')
            ->emptyStateIcon('heroicon-o-cog-8-tooth');
    }
}
