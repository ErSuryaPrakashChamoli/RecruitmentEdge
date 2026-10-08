<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * SaaS-1: the pivot class for every belongsToMany between tenant-owned models. attach(), sync()
 * and Filament relationship selects then write pivot rows through this model, so each row takes
 * the current tenant (and its references are checked) like any other tenant-owned row.
 */
class TenantPivot extends Pivot
{
    use BelongsToTenant;
}
