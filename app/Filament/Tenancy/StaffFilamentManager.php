<?php

namespace App\Filament\Tenancy;

use Filament\FilamentManager;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS-2: Filament's manager, with one change — a person's default tenant is exactly what
 * User::getDefaultTenant() decides. Filament would otherwise fall back to the first tenant in the
 * list, silently placing a person who belongs to several tenants (or whose default became
 * inaccessible) into whichever tenant sorts first. With no default, Filament sends them to /admin,
 * where the organisation chooser asks (StaffRedirectToTenantController).
 */
class StaffFilamentManager extends FilamentManager
{
    public function getUserDefaultTenant(HasTenants|Model|Authenticatable $user): ?Model
    {
        return $user instanceof HasDefaultTenant ? $user->getDefaultTenant($this->getCurrentOrDefaultPanel()) : null;
    }
}
