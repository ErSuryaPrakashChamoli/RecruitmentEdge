<?php

namespace App\Filament\Resources\AutomationRules\Pages;

use App\Filament\Resources\AutomationRules\AutomationRuleActions;
use App\Filament\Resources\AutomationRules\AutomationRuleResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAutomationRule extends ViewRecord
{
    protected static string $resource = AutomationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AutomationRuleActions::activate(),
            AutomationRuleActions::pause(),
            AutomationRuleActions::dryRun(),
            EditAction::make(),
            ActionGroup::make([
                AutomationRuleActions::duplicate(),
                AutomationRuleActions::archive(),
            ]),
        ];
    }
}
