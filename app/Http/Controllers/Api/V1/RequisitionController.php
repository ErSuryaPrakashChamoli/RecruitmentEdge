<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RequisitionStatus;
use App\Http\Resources\Api\V1\JobPostingResource;
use App\Http\Resources\Api\V1\RequisitionResource;
use App\Models\JobPosting;
use App\Models\RecruitmentRequisition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * SaaS-6: requisitions and their job postings the credential's owner may see (requisitions.viewAny,
 * RecruitmentRequisition::visibleTo).
 */
class RequisitionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', RecruitmentRequisition::class);

        return $this->list($request, RecruitmentRequisition::query()->visibleTo($this->principal($request)->owner)->with(['department', 'designation', 'location']), RequisitionResource::class, [
            'status' => ['sometimes', Rule::enum(RequisitionStatus::class)],
            'department_id' => ['sometimes', 'integer'],
            'location_id' => ['sometimes', 'integer'],
        ], [
            'status' => fn ($query, $value) => $query->where('status', $value),
            'department_id' => fn ($query, $value) => $query->where('department_id', (int) $value),
            'location_id' => fn ($query, $value) => $query->where('location_id', (int) $value),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $requisition = RecruitmentRequisition::query()->visibleTo($this->principal($request)->owner)->with(['department', 'designation', 'location'])->findOrFail($id);
        Gate::authorize('view', $requisition);

        return $this->one($request, new RequisitionResource($requisition));
    }

    public function postings(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', RecruitmentRequisition::class);
        $owner = $this->principal($request)->owner;

        return $this->list($request, JobPosting::query()->whereHas('requisition', fn ($requisitions) => $requisitions->visibleTo($owner)), JobPostingResource::class, [
            'requisition_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'in:draft,published,paused,closed'],
        ], [
            'requisition_id' => fn ($query, $value) => $query->where('requisition_id', (int) $value),
            'status' => fn ($query, $value) => $query->where('status', $value),
        ]);
    }

    public function posting(Request $request, int $id): JsonResponse
    {
        Gate::authorize('viewAny', RecruitmentRequisition::class);
        $owner = $this->principal($request)->owner;
        $posting = JobPosting::query()->whereHas('requisition', fn ($requisitions) => $requisitions->visibleTo($owner))->findOrFail($id);

        return $this->one($request, new JobPostingResource($posting));
    }
}
