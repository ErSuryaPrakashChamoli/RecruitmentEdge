<?php

namespace App\Filament\Resources\RecruitmentDailyTargets\Pages;

use App\Filament\Resources\RecruitmentDailyTargets\RecruitmentDailyTargetResource;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateRecruitmentDailyTarget extends CreateRecord
{
    protected static string $resource = RecruitmentDailyTargetResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Filament::auth()->user()?->employee_id;

        return $data;
    }

    protected function beforeCreate(): void
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
