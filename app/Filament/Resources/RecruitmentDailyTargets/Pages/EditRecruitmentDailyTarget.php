<?php

namespace App\Filament\Resources\RecruitmentDailyTargets\Pages;

use App\Filament\Resources\RecruitmentDailyTargets\RecruitmentDailyTargetResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditRecruitmentDailyTarget extends EditRecord
{
    protected static string $resource = RecruitmentDailyTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $data = $this->form->getState();
        $scopeCount = collect([$data['employee_id'] ?? null, $data['department_id'] ?? null, $data['designation_id'] ?? null])
            ->filter()
            ->count();

        if ($scopeCount !== 1) {
            Notification::make()
                ->danger()
                ->title('Set exactly one scope')
                ->body('Choose exactly one of Recruiter, Department, or Designation for this target.')
                ->send();

            $this->halt();
        }
    }
}
