<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Filament\Actions\Imports\Models\Import as FilamentImport;

/**
 * SaaS-1: Filament's import record, owned by the tenant it was started in (bound in
 * TenancyServiceProvider, like Export).
 */
class Import extends FilamentImport
{
    use BelongsToTenant;
}
