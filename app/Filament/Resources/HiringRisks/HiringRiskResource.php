<?php

namespace App\Filament\Resources\HiringRisks;

use App\Enums\HiringRiskStatus;
use App\Enums\HiringRiskType;
use App\Enums\RiskSeverity;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Filament\Resources\HiringRisks\Pages\ListHiringRisks;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\HiringRisk;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Intelligence\EvidenceLookup;
use App\Services\Intelligence\HiringRiskRadar;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Hiring Risk Radar™ register (Phase 7): every detected risk with severity, evidence, owner and
 * recommended action, and its lifecycle. Scoped like requisitions; risks without a requisition
 * (interviewer backlog, automation rule) follow the owner's hierarchy.
 */
class HiringRiskResource extends Resource
{
    use GuardsDomainExceptions;

    protected static ?string $model = HiringRisk::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'EDGE Intelligence';

    protected static ?string $navigationLabel = 'Hiring Risk Radar';

    protected static ?string $modelLabel = 'hiring risk';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();
        $visible = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        return parent::getEloquentQuery()->when($visible !== null, fn (Builder $query) => $query->where(fn (Builder $q) => $q
            ->whereIn('requisition_id', RecruitmentRequisition::query()->visibleTo($user)->select('id'))
            ->orWhere(fn (Builder $none) => $none->whereNull('requisition_id')->whereIn('owner_id', $visible))));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = self::getEloquentQuery()->open()->whereIn('severity', [RiskSeverity::Critical, RiskSeverity::High])->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['requisition:id,code', 'owner:id,first_name,last_name']))
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('severity')->badge()->formatStateUsing(fn (RiskSeverity $state) => $state->label())->color(fn (RiskSeverity $state) => $state->color()),
                TextColumn::make('title')->searchable()->wrap()->description(fn (HiringRisk $record) => str($record->recommended_action)->prepend('→ ')->toString()),
                TextColumn::make('type')->formatStateUsing(fn (HiringRiskType $state) => $state->label())->toggleable(),
                TextColumn::make('requisition.code')->label('Requisition')->placeholder('—'),
                TextColumn::make('owner.first_name')->label('Owner')->formatStateUsing(fn (HiringRisk $record) => $record->owner?->fullName())->placeholder('—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (HiringRiskStatus $state) => $state->label())->color(fn (HiringRiskStatus $state) => $state->color())
                    ->description(fn (HiringRisk $record) => $record->resolution),
                TextColumn::make('first_detected_at')->label('Detected')->since()->sortable(),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(HiringRiskStatus::options())->multiple()->default([HiringRiskStatus::Open->value, HiringRiskStatus::Acknowledged->value]),
                SelectFilter::make('severity')->options(RiskSeverity::options())->multiple(),
                SelectFilter::make('type')->options(HiringRiskType::options()),
                SelectFilter::make('requisition')->relationship('requisition', 'code')->searchable(),
            ])
            ->recordUrl(fn (HiringRisk $record) => $record->requisition_id !== null ? RecruitmentRequisitionResource::getUrl('intelligence', ['record' => $record->requisition_id]) : null)
            ->recordActions([
                Action::make('why')
                    ->label('Why?')
                    ->icon('heroicon-o-magnifying-glass-circle')
                    ->slideOver()
                    ->modalWidth(Width::TwoExtraLarge)
                    ->modalHeading(fn (HiringRisk $record) => $record->title)
                    ->modalContent(fn (HiringRisk $record) => view('filament.intelligence.evidence', ['rows' => app(EvidenceLookup::class)->for(auth()->user(), 'hiring_risk', $record->id)]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                ActionGroup::make([
                    Action::make('acknowledge')
                        ->icon('heroicon-o-eye')
                        ->visible(fn (HiringRisk $record) => $record->status === HiringRiskStatus::Open && (auth()->user()?->can('manage', $record) ?? false))
                        ->action(function (HiringRisk $record): void {
                            self::guarded('Could not acknowledge', fn () => app(HiringRiskRadar::class)->acknowledge($record, auth()->user()));
                            Notification::make()->title('Risk acknowledged')->success()->send();
                        }),
                    Action::make('resolve')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (HiringRisk $record) => $record->isOpen() && (auth()->user()?->can('manage', $record) ?? false))
                        ->schema([Textarea::make('note')->label('What was done?')->rows(2)])
                        ->action(function (HiringRisk $record, array $data): void {
                            self::guarded('Could not resolve', fn () => app(HiringRiskRadar::class)->resolve($record, auth()->user(), $data['note'] ?? null));
                            Notification::make()->title('Risk resolved')->success()->send();
                        }),
                    Action::make('dismiss')
                        ->icon('heroicon-o-x-circle')
                        ->color('gray')
                        ->visible(fn (HiringRisk $record) => $record->isOpen() && (auth()->user()?->can('manage', $record) ?? false))
                        ->schema([Textarea::make('reason')->required()->rows(2)->helperText('The same risk will not be raised again for 7 days.')])
                        ->action(function (HiringRisk $record, array $data): void {
                            self::guarded('Could not dismiss', fn () => app(HiringRiskRadar::class)->dismiss($record, auth()->user(), $data['reason']));
                            Notification::make()->title('Risk dismissed')->success()->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('No hiring risks')
            ->emptyStateDescription('The Risk Radar scans open requisitions every hour.')
            ->emptyStateIcon('heroicon-o-shield-check');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHiringRisks::route('/'),
        ];
    }
}
