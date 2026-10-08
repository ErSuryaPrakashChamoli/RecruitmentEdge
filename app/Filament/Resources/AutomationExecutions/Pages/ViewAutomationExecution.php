<?php

namespace App\Filament\Resources\AutomationExecutions\Pages;

use App\Filament\Resources\AutomationExecutions\AutomationExecutionActions;
use App\Filament\Resources\AutomationExecutions\AutomationExecutionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAutomationExecution extends ViewRecord
{
    protected static string $resource = AutomationExecutionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AutomationExecutionActions::retry(),
            AutomationExecutionActions::cancel(),
        ];
    }
}
