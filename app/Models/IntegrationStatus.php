<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The last explicit connection test of an external integration — the only evidence the platform
 * uses to call an integration "operational". Written by IntegrationRegistry::test().
 */
#[Fillable(['provider', 'category', 'last_test_ok', 'last_test_message', 'last_tested_at', 'last_tested_by'])]
class IntegrationStatus extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'last_test_ok' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function lastTestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'last_tested_by');
    }
}
