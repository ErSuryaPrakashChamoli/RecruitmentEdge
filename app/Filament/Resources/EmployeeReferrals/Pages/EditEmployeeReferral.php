<?php

namespace App\Filament\Resources\EmployeeReferrals\Pages;

use App\Filament\Resources\EmployeeReferrals\EmployeeReferralResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Reviewers may correct the relationship/notes. Status changes and bonus eligibility are audited
 * header actions on the view page.
 */
class EditEmployeeReferral extends EditRecord
{
    protected static string $resource = EmployeeReferralResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * Only the relationship and notes are editable here; bonus eligibility is changed through the
     * audited "Bonus eligibility" action (ReferralService::setIncentiveEligibility()).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return array_intersect_key($data, array_flip(['relationship', 'notes']));
    }
}
