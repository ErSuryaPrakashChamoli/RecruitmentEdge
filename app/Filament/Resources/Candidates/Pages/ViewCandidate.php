<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Filament\Resources\Candidates\CandidateResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * The person-level 360 view (contact/professional info + every application across requisitions,
 * already in the relation manager tabs). A candidate isn't "in a stage" — that's per-application —
 * so unlike ViewCandidateApplication this gets only a light header touch (avatar + quick actions),
 * no journey/timeline.
 */
class ViewCandidate extends ViewRecord
{
    protected static string $resource = CandidateResource::class;

    protected string $view = 'filament.resources.candidates.view';

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
