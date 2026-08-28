<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Models\Concerns\Auditable;
use App\Observers\EmployeeObserver;
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
])]
#[ObservedBy(EmployeeObserver::class)]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use Auditable, HasFactory, SoftDeletes;

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

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
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
}
