<?php

namespace App\Filament\Resources\TenantInvitations\Pages;

use App\Filament\Resources\TenantInvitations\TenantInvitationResource;
use App\Filament\Resources\Users\Actions\InviteMemberAction;
use Filament\Resources\Pages\ListRecords;

class ListTenantInvitations extends ListRecords
{
    protected static string $resource = TenantInvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InviteMemberAction::make(),
        ];
    }
}
