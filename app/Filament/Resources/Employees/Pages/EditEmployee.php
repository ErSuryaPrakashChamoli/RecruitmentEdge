<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Pages\AiCopilot;
use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('askAi')
                ->label('Analyze with AI')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn () => (bool) auth()->user()?->can('ai.query'))
                ->url(fn () => AiCopilot::linkForContext('employee', $this->record->id)),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
