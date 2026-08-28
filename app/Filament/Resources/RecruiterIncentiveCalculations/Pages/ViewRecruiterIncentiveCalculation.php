<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations\Pages;

use App\Filament\Resources\RecruiterIncentiveCalculations\RecruiterIncentiveCalculationResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * No EditAction: calculations have no editable fields — see RecruiterIncentiveCalculationForm.
 * This page exists only to host the Approvals/Adjustments/Payments relation manager tabs.
 */
class ViewRecruiterIncentiveCalculation extends ViewRecord
{
    protected static string $resource = RecruiterIncentiveCalculationResource::class;
}
