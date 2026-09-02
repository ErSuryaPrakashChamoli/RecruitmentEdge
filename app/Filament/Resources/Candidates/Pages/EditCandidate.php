<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Filament\Pages\AiCopilot;
use App\Filament\Resources\Candidates\CandidateResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditCandidate extends EditRecord
{
    protected static string $resource = CandidateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('askAi')
                ->label('Ask AI about this candidate')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn () => (bool) auth()->user()?->can('ai.query'))
                ->url(fn () => AiCopilot::linkForContext('candidate', $this->record->id)),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
