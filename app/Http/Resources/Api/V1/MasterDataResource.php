<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SaaS-6 API v1: a department, location or designation — the fields every member may see.
 *
 * @property-read Department|Location|Designation $resource
 */
class MasterDataResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->resource->getKey(),
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'city' => $this->resource->getAttribute('city'),
            'state' => $this->resource->getAttribute('state'),
            'country' => $this->resource->getAttribute('country'),
            'department_id' => $this->resource->getAttribute('department_id'),
            'is_active' => (bool) $this->resource->is_active,
            'archived' => method_exists($this->resource, 'trashed') && $this->resource->trashed(),
        ], fn (mixed $value): bool => $value !== null);
    }
}
