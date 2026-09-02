<?php

namespace App\Services\AI\Tools\JobTools;

use App\Enums\AiRiskLevel;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;

class SearchRequisitionsTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'search_requisitions';
    }

    public function description(): string
    {
        return 'Search open positions/requisitions by department, designation, location, or status, scoped to what the current user is allowed to see.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'location' => ['type' => 'string', 'description' => 'Location name to filter by, e.g. Delhi'],
                'designation' => ['type' => 'string', 'description' => 'Designation/role name to filter by'],
                'status' => ['type' => 'string', 'description' => 'One of: draft, pending_approval, open, on_hold, closed, cancelled'],
                'limit' => ['type' => 'integer'],
            ],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'requisitions.viewAny';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $visibleIds = $this->visibleEmployeeIds($user);
        $limit = min((int) ($arguments['limit'] ?? 15), 30);

        $requisitions = RecruitmentRequisition::query()
            ->with(['department', 'designation', 'location'])
            ->when(filled($arguments['location'] ?? null), fn (Builder $q) => $q->whereHas(
                'location',
                fn (Builder $l) => $l->where('name', 'like', '%'.$arguments['location'].'%'),
            ))
            ->when(filled($arguments['designation'] ?? null), fn (Builder $q) => $q->whereHas(
                'designation',
                fn (Builder $d) => $d->where('name', 'like', '%'.$arguments['designation'].'%'),
            ))
            ->when(filled($arguments['status'] ?? null), fn (Builder $q) => $q->where('status', $arguments['status']))
            ->when($visibleIds !== null, fn (Builder $q) => $q->where(function (Builder $q2) use ($visibleIds): void {
                $q2->whereIn('manager_id', $visibleIds)
                    ->orWhereIn('assistant_manager_id', $visibleIds)
                    ->orWhereIn('vp_hr_id', $visibleIds)
                    ->orWhereIn('created_by', $visibleIds)
                    ->orWhereHas('recruiters', fn (Builder $r) => $r->whereIn('employees.id', $visibleIds));
            }))
            ->limit($limit)
            ->get();

        $rows = $requisitions->map(fn (RecruitmentRequisition $r) => [
            'id' => $r->id,
            'code' => $r->code,
            'designation' => $r->designation?->name,
            'department' => $r->department?->name,
            'location' => $r->location?->name,
            'openings' => $r->openings,
            'remaining_openings' => $r->remainingOpenings(),
            'status' => $r->status->label(),
            'ageing_days' => $r->ageingInDays(),
        ]);

        return ToolResult::ok(
            data: ['requisitions' => $rows->toArray()],
            summary: "Found {$rows->count()} requisition(s).",
            type: 'job_list',
        );
    }
}
