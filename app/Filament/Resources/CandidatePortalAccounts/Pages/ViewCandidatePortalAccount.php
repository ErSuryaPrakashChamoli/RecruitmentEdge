<?php

namespace App\Filament\Resources\CandidatePortalAccounts\Pages;

use App\Filament\Resources\CandidatePortalAccounts\CandidatePortalAccountResource;
use App\Filament\Resources\CandidatePortalAccounts\Tables\CandidatePortalAccountsTable;
use Filament\Resources\Pages\ViewRecord;

class ViewCandidatePortalAccount extends ViewRecord
{
    protected static string $resource = CandidatePortalAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CandidatePortalAccountsTable::resendAction(),
            CandidatePortalAccountsTable::revokeAction(),
        ];
    }
}
