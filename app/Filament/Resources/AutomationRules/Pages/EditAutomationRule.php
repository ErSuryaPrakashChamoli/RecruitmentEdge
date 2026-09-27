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
 * Saving a changed configuration creates a new rule version. Runs already scheduled are cancelled
 * unless the editor chooses to keep them on the version they were created with (Phase 8.7).
 */
class EditAutomationRule extends EditRecord
{
    protected static string $resource = AutomationRuleResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var AutomationRule $rule */
        $rule = $this->getRecord();

        // Phase 8.7 (D8.7-009 c): a field default is not applied when an edit page fills from the
        // record, so the "cancel scheduled runs" default is set here.
        return [...AutomationRuleFormData::toForm($rule), 'pending_runs' => 'cancel'];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var AutomationRule $record */
        return AutomationRuleActions::guarded('The rule could not be saved', fn () => app(AutomationRuleService::class)->update($record, AutomationRuleFormData::fromForm($data), auth()->user(), $data['change_reason'] ?? null, ($data['pending_runs'] ?? 'cancel') === 'keep'));
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
