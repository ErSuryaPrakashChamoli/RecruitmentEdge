<?php

namespace App\Filament\Resources\AutomationRules\Pages;

use App\Filament\Resources\AutomationRules\AutomationRuleActions;
use App\Filament\Resources\AutomationRules\AutomationRuleResource;
use App\Filament\Resources\AutomationRules\Schemas\AutomationRuleFormData;
use App\Models\AutomationRule;
use App\Services\Automation\AutomationRuleService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Saving a changed configuration creates a new rule version; runs already created keep the
 * version they started with.
 */
class EditAutomationRule extends EditRecord
{
    protected static string $resource = AutomationRuleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var AutomationRule $rule */
        $rule = $this->getRecord();

        return AutomationRuleFormData::toForm($rule);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var AutomationRule $record */
        return AutomationRuleActions::guarded('The rule could not be saved', fn () => app(AutomationRuleService::class)->update($record, AutomationRuleFormData::fromForm($data), auth()->user(), $data['change_reason'] ?? null));
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            AutomationRuleActions::dryRun(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
