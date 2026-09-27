<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\GuardsLifecycleAttributes;
use App\Models\Concerns\ReferencesActiveMasterData;
use App\Observers\EmployeeObserver;
use Carbon\CarbonInterface;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'candidate_id',
    'employee_code',
    'first_name',
    'last_name',
    'email',
    'mobile',
    'department_id',
    'designation_id',
    'location_id',
    'reports_to_id',
    'date_of_joining',
    'status',
    'category',
    'level',
    'photo_path',
])]
#[ObservedBy(EmployeeObserver::class)]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use Auditable, GuardsLifecycleAttributes, HasFactory, ReferencesActiveMasterData, SoftDeletes;

    /**
     * Phase 8.4: employment state changes only through EmployeeLifecycleService, and the reporting
     * line (the access boundary) only through HierarchyIntegrityService.
     *
     * @return array<int, string>
     */
    public function lifecycleAttributes(): array
    {
        return ['status', 'reports_to_id'];
    }

    public function lifecycleOwner(): string
    {
        return 'EmployeeLifecycleService / HierarchyIntegrityService';
    }

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'date_of_joining' => 'date',
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reports_to_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'reports_to_id');
    }

    /**
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * The candidate this employee was converted from, if hired via the recruitment pipeline
     * (Section 44: candidate-to-employee conversion preserves recruitment history).
     *
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * Phase 8.2: the minimal separation record, if the employee has left. Phase 8.4: the latest
     * one that was not cancelled — an employee rehired after a separation can separate again.
     *
     * @return HasOne<EmployeeSeparation, $this>
     */
    public function separation(): HasOne
    {
        return $this->hasOne(EmployeeSeparation::class)->ofMany(['id' => 'max'], fn ($query) => $query->whereNull('cancelled_at'));
    }

    /**
     * Phase 8.4: every separation, cancelled ones included (history).
     *
     * @return HasMany<EmployeeSeparation, $this>
     */
    public function separations(): HasMany
    {
        return $this->hasMany(EmployeeSeparation::class);
    }

    /**
     * Phase 8.4: the separation that ended the employment which started on $joinedOn — the
     * earliest non-cancelled separation dated on or after it. A separation from an earlier
     * employment (before a rehire) never counts against a later one.
     */
    public function separationForEmploymentFrom(CarbonInterface $joinedOn): ?EmployeeSeparation
    {
        return $this->separations
            ->filter(fn (EmployeeSeparation $separation) => $separation->cancelled_at === null && $separation->separation_date->copy()->startOfDay()->gte($joinedOn->copy()->startOfDay()))
            ->sortBy(fn (EmployeeSeparation $separation) => $separation->separation_date->toDateString())
            ->first();
    }

    /**
     * All employees above this one in the hierarchy (manager, manager's manager, ...), via the closure table.
     *
     * @return BelongsToMany<Employee, $this>
     */
    public function ancestors(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_hierarchy', 'descendant_id', 'ancestor_id')
            ->withPivot('depth')
            ->wherePivot('depth', '>', 0);
    }

    /**
     * All employees below this one in the hierarchy (direct and indirect reports), via the closure table.
     *
     * @return BelongsToMany<Employee, $this>
     */
    public function descendants(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_hierarchy', 'ancestor_id', 'descendant_id')
            ->withPivot('depth')
            ->wherePivot('depth', '>', 0);
    }

    /**
     * Referrals this employee has submitted (Phase 4).
     *
     * @return HasMany<EmployeeReferral, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(EmployeeReferral::class, 'referrer_id');
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
            'designation_id' => Designation::class,
            'location_id' => Location::class,
        ];
    }
}
