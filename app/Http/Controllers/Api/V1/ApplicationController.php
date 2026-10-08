<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Http\Resources\Api\V1\ApplicationResource;
use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * SaaS-6: applications the credential's owner may see (candidates.viewAny; recruiter within the
 * owner's hierarchy — the rule of the Applications screen). Read-only: stage and status change
 * only through people, never through the API.
 */
class ApplicationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CandidateApplication::class);

        return $this->list($request, $this->visible($this->principal($request)->owner), ApplicationResource::class, [
            'candidate_id' => ['sometimes', 'integer'],
            'requisition_id' => ['sometimes', 'integer'],
            'job_posting_id' => ['sometimes', 'integer'],
            'stage' => ['sometimes', Rule::enum(CandidateStage::class)],
            'status' => ['sometimes', Rule::enum(ApplicationStatus::class)],
        ], [
            'candidate_id' => fn ($query, $value) => $query->where('candidate_id', (int) $value),
            'requisition_id' => fn ($query, $value) => $query->where('requisition_id', (int) $value),
            'job_posting_id' => fn ($query, $value) => $query->where('job_posting_id', (int) $value),
            'stage' => fn ($query, $value) => $query->where('current_stage', $value),
            'status' => fn ($query, $value) => $query->where('status', $value),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $application = $this->visible($this->principal($request)->owner)->findOrFail($id);
        Gate::authorize('view', $application);

        return $this->one($request, new ApplicationResource($application));
    }

    /**
     * @return Builder<CandidateApplication>
     */
    private function visible(User $owner): Builder
    {
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($owner);

        return CandidateApplication::query()->when($visibleIds !== null, fn (Builder $query) => $query->whereIn('recruiter_id', $visibleIds));
    }
}
