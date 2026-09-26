<?php

namespace App\Filament\Resources\AutomationRules\Pages;

use App\Filament\Resources\AutomationRules\AutomationRuleActions;
use App\Filament\Resources\AutomationRules\AutomationRuleResource;
use App\Filament\Resources\AutomationRules\Schemas\AutomationRuleFormData;
use App\Services\Automation\AutomationRuleService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * New rules are saved as Drafts through AutomationRuleService (validated and versioned).
 */
class CreateAutomationRule extends CreateRecord
{
    protected static string $resource = AutomationRuleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return AutomationRuleActions::guarded('The rule could not be saved', fn () => app(AutomationRuleService::class)->create(AutomationRuleFormData::fromForm($data), auth()->user()));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
