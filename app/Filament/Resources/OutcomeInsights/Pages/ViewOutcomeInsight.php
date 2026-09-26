<?php

namespace App\Filament\Resources\OutcomeInsights\Pages;

use App\Enums\OutcomeInsightKind;
use App\Enums\RequirementLevel;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Filament\Resources\OutcomeInsights\OutcomeInsightResource;
use App\Models\OutcomeInsight;
use App\Models\RecruitmentRequisition;
use App\Services\Outcomes\OutcomeLearningService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewOutcomeInsight extends ViewRecord
{
    use GuardsDomainExceptions;

    protected static string $resource = OutcomeInsightResource::class;

    protected function getHeaderActions(): array
    {
        /** @var OutcomeInsight $record */
        $record = $this->getRecord();
        $canReview = fn (): bool => auth()->user()?->can('review', $record) ?? false;
        $appliesToRoleDna = $record->kind === OutcomeInsightKind::RoleDnaLearning && (auth()->user()?->can('intelligence.role-dna.manage') ?? false);

        return [
            Action::make('accept')
                ->label('Accept')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible($canReview)
                ->schema([
                    Select::make('requisition_id')
                        ->label('Also add to the Role DNA of')
                        ->helperText('Optional. Leave empty to keep it as a historical pattern for this designation only.')
                        ->options(fn () => RecruitmentRequisition::query()->visibleTo(auth()->user())->where('designation_id', $record->designation_id)->orderBy('code')->pluck('code', 'id'))
                        ->searchable()
                        ->visible($appliesToRoleDna),
                    Select::make('level')
                        ->label('As')
                        ->options([RequirementLevel::Preferred->value => RequirementLevel::Preferred->label(), RequirementLevel::Informational->value => RequirementLevel::Informational->label()])
                        ->default(RequirementLevel::Preferred->value)
                        ->required()
                        ->visible($appliesToRoleDna),
                    Textarea::make('reason')->label('Note (optional)')->rows(2)->maxLength(255),
                ])
                ->modalDescription($record->kind === OutcomeInsightKind::RoleDnaLearning
                    ? 'The pattern is shown in Role DNA history for this designation. It is never a required skill and never excludes a candidate.'
                    : 'Records this aggregate pattern in Hiring Memory.')
                ->action(function (array $data) use ($record): void {
                    $requisition = filled($data['requisition_id'] ?? null) ? RecruitmentRequisition::query()->findOrFail($data['requisition_id']) : null;
                    self::guarded('Could not accept the insight', fn () => app(OutcomeLearningService::class)->accept($record, auth()->user(), $requisition, RequirementLevel::from($data['level'] ?? RequirementLevel::Preferred->value), $data['reason'] ?? null));
                    $this->decided('Insight accepted');
                }),
            Action::make('defer')
                ->label('Defer')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->visible($canReview)
                ->schema([Textarea::make('reason')->label('Note (optional)')->rows(2)->maxLength(255)])
                ->action(function (array $data) use ($record): void {
                    self::guarded('Could not defer the insight', fn () => app(OutcomeLearningService::class)->defer($record, auth()->user(), $data['reason'] ?? null));
                    $this->decided('Insight deferred');
                }),
            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-mark')
                ->color('danger')
                ->visible($canReview)
                ->schema([Textarea::make('reason')->required()->rows(2)->maxLength(255)])
                ->action(function (array $data) use ($record): void {
                    self::guarded('Could not reject the insight', fn () => app(OutcomeLearningService::class)->reject($record, auth()->user(), $data['reason']));
                    $this->decided('Insight rejected');
                }),
        ];
    }

    private function decided(string $title): void
    {
        Notification::make()->title($title)->success()->send();
        $this->redirect(OutcomeInsightResource::getUrl('view', ['record' => $this->getRecord()]));
    }
}
