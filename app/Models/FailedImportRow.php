<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Filament\Actions\Imports\Models\FailedImportRow as FilamentFailedImportRow;

/**
 * SaaS-1: a row an import could not process — owned by the import's tenant (bound in
 * TenancyServiceProvider).
 */
class FailedImportRow extends FilamentFailedImportRow
{
    use BelongsToTenant;
}
