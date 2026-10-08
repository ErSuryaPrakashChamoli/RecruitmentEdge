<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GovernedMasterData;
use App\Models\Concerns\ReferencesActiveMasterData;
use Database\Factories\DesignationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'code', 'department_id', 'is_active'])]
class Designation extends Model
{
    /** @use HasFactory<DesignationFactory> */
    use Auditable, BelongsToTenant, GovernedMasterData, HasFactory, ReferencesActiveMasterData, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Phase 8.6 (D8.6-005): master data taken up by this record must be in service.
     *
     * @return array<string, class-string<Model>>
     */
    public function activeMasterDataReferences(): array
    {
        return [
            'department_id' => Department::class,
        ];
    }
}
