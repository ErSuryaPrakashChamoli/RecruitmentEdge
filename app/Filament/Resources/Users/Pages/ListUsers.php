<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Actions\InviteMemberAction;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // SaaS-2: people join by invitation; logins are never created here.
            InviteMemberAction::make(),
        ];
    }
}
