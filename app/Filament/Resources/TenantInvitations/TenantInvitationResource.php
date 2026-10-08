<?php

namespace App\Filament\Resources\TenantInvitations;

use App\Filament\Resources\TenantInvitations\Pages\ListTenantInvitations;
use App\Filament\Resources\TenantInvitations\Tables\TenantInvitationsTable;
use App\Models\TenantInvitation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * SaaS-2: this organisation's invitations — who was invited, with which roles, by whom, and where
 * each stands. Invitations are sent from the Users screen; here they are resent or revoked. A
 * tenant-owned list (TenantScope and Filament tenancy), never another tenant's.
 */
class TenantInvitationResource extends Resource
{
    protected static ?string $model = TenantInvitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Invitations';

    protected static ?string $modelLabel = 'invitation';

    public static function table(Table $table): Table
    {
        return TenantInvitationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenantInvitations::route('/'),
        ];
    }
}
