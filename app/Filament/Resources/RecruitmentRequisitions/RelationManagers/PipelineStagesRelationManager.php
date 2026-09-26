<?php

namespace App\Filament\Resources\RecruitmentRequisitions\RelationManagers;

use App\Enums\CandidateStage;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentRequisition;
use App\Models\RequisitionPipelineStage;
use App\Services\PipelineTemplateService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The requisition's own pipeline snapshot. Read-only — a snapshot changes only by applying a
 * template again (PipelineTemplateService::applyToRequisition), which supersedes it as a whole.
 */
class PipelineStagesRelationManager extends RelationManager
{
    protected static string $relationship = 'pipelineStages';

    protected static ?string $title = 'Hiring Pipeline';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->description(function (): string {
                /** @var RecruitmentRequisition $requisition */
                $requisition = $this->getOwnerRecord();

                return $requisition->hasConfiguredPipeline()
                    ? 'From "'.($requisition->pipelineTemplate?->name ?? 'a template').'" v'.$requisition->pipeline_template_version.', applied '.$requisition->pipeline_applied_at->diffForHumans().'.'
                    : 'No configured pipeline yet — this requisition uses the canonical stages.';
            })
            ->modifyQueryUsing(fn ($query) => $query->withCount(['applications' => fn ($q) => $q->where('status', 'active')]))
            ->columns([
                TextColumn::make('name')
                    ->badge()
                    ->color(fn (RequisitionPipelineStage $record): string => $record->color),
                TextColumn::make('milestone')
                    ->label('Counts as')
                    ->formatStateUsing(fn (CandidateStage $state): string => $state->label()),
                TextColumn::make('sla_hours')
                    ->label('SLA')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state} h" : '—')
                    ->placeholder('—'),
                IconColumn::make('is_skippable')->label('Skippable')->boolean(),
                IconColumn::make('is_terminal')->label('Terminal')->boolean(),
                TextColumn::make('applications_count')->label('Active candidates'),
            ])
            ->paginated(false)
            ->headerActions([
                $this->applyTemplateAction(),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public function applyTemplateAction(): Action
    {
        return Action::make('applyTemplate')
            ->label('Apply template')
            ->icon('heroicon-o-queue-list')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('update', $this->getOwnerRecord()))
            ->modalDescription('Replaces this requisition\'s pipeline with a fresh copy of the template. Candidates keep their progress: each moves to the matching stage of the new pipeline, and stage history is preserved.')
            ->schema([
                Select::make('pipeline_template_id')
                    ->label('Template')
                    ->options(fn (): array => RecruitmentPipelineTemplate::activeOptions())
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var RecruitmentRequisition $requisition */
                $requisition = $this->getOwnerRecord();
                $template = RecruitmentPipelineTemplate::query()->active()->findOrFail($data['pipeline_template_id']);

                InterviewsTable::guarded('Template could not be applied', fn () => app(PipelineTemplateService::class)->applyToRequisition($requisition, $template, auth()->user()?->employee));

                Notification::make()->title('Pipeline template applied')->success()->send();
            });
    }
}
