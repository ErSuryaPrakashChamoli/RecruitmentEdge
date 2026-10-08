<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\MasterDataResource;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * SaaS-6: departments, locations and designations (every member may read them).
 */
class MasterDataController extends ApiController
{
    public function departments(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Department::class);

        return $this->list($request, Department::query(), MasterDataResource::class, ['active' => ['sometimes', 'boolean']], ['active' => fn ($query, $value) => $query->where('is_active', filter_var($value, FILTER_VALIDATE_BOOLEAN))]);
    }

    public function locations(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Location::class);

        return $this->list($request, Location::query(), MasterDataResource::class, ['active' => ['sometimes', 'boolean']], ['active' => fn ($query, $value) => $query->where('is_active', filter_var($value, FILTER_VALIDATE_BOOLEAN))]);
    }

    public function designations(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Designation::class);

        return $this->list($request, Designation::query(), MasterDataResource::class, ['active' => ['sometimes', 'boolean'], 'department_id' => ['sometimes', 'integer']], [
            'active' => fn ($query, $value) => $query->where('is_active', filter_var($value, FILTER_VALIDATE_BOOLEAN)),
            'department_id' => fn ($query, $value) => $query->where('department_id', (int) $value),
        ]);
    }
}
