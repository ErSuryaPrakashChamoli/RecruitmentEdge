<?php

namespace App\Models;

use App\Enums\OutcomeCaptureMode;
use Database\Factories\HiringOutcomeSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Outcome Loop™ (Phase 8.2): the hiring journey as it was known at the moment of a completed join,
 * anchored to the joining record. Immutable — later edits to the candidate, requisition, pipeline
 * or Role DNA never change it. The only later write allowed is linking the employee created from
 * the joining (once). References and safe categories only; no names, contact details or pay.
 */
#[Fillable([
    'candidate_joining_id', 'candidate_application_id', 'candidate_id', 'requisition_id', 'employee_id',
    'designation_id', 'department_id', 'location_id', 'source_id', 'department_name', 'designation_name', 'location_name', 'source_name',
    'role_dna_version_id', 'pipeline_template_version',
    'joined_on', 'time_to_hire_days', 'facts', 'capture_mode', 'rules_version', 'captured_at',
])]
class HiringOutcomeSnapshot extends Model
{
    /** @use HasFactory<HiringOutcomeSnapshotFactory> */
    use HasFactory;

    /**
     * hiring-snapshot/2 (Phase 8.5 D2): time to hire ends only at the actual joining date.
     * hiring-snapshot/3 (Phase 8.6 D8.6-008): the department, designation, location and source names
     * are frozen with the snapshot. Snapshots captured under /1 and /2 keep their stored values and
     * version; their names resolve from the (possibly renamed or archived) current record.
     */
    public const string RULES_VERSION = 'hiring-snapshot/3';

    /**
     * The name of a dimension as it was when the hire completed (hiring-snapshot/3), else the
     * current record's name (older snapshots), else null.
     */
    public function dimensionName(string $dimension): ?string
    {
        $frozen = $this->getAttribute("{$dimension}_name");

        if (filled($frozen)) {
            return $frozen;
        }

        return match ($dimension) {
            'department' => Department::withTrashed()->find($this->department_id)?->name,
            'designation' => $this->designation?->name,
            'location' => Location::withTrashed()->find($this->location_id)?->name,
            'source' => $this->source?->name,
            default => null,
        };
    }

    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
            'facts' => 'array',
            'capture_mode' => OutcomeCaptureMode::class,
            'captured_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $snapshot): void {
            $dirty = array_keys($snapshot->getDirty());
            $linkingEmployee = $dirty === ['employee_id'] || $dirty === ['employee_id', 'updated_at'];

            if (! $linkingEmployee || $snapshot->getOriginal('employee_id') !== null) {
                throw new LogicException('A hiring outcome snapshot is immutable — only the employee link may be set, once.');
            }
        });
    }

    /**
     * @return BelongsTo<CandidateJoining, $this>
     */
    public function joining(): BelongsTo
    {
        return $this->belongsTo(CandidateJoining::class, 'candidate_joining_id');
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class)->withTrashed();
    }

    /**
     * @return BelongsTo<CandidateSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CandidateSource::class)->withTrashed();
    }

    /**
     * @return HasMany<HiringOutcome, $this>
     */
    public function outcomes(): HasMany
    {
        return $this->hasMany(HiringOutcome::class);
    }
}
