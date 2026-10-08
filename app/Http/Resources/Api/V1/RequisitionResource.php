<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RecruitmentRequisition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SaaS-6 API v1: a requisition — no salary range, remarks or internal manager assignments.
 *
 * @property-read RecruitmentRequisition $resource
 */
class RequisitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $requisition = $this->resource;

        return [
            'id' => $requisition->getKey(),
            'code' => $requisition->code,
            'status' => $requisition->status?->value,
            'department' => $requisition->department !== null ? ['id' => $requisition->department->getKey(), 'name' => $requisition->department->name] : null,
            'designation' => $requisition->designation !== null ? ['id' => $requisition->designation->getKey(), 'name' => $requisition->designation->name] : null,
            'location' => $requisition->location !== null ? ['id' => $requisition->location->getKey(), 'name' => $requisition->location->name] : null,
            'openings' => (int) $requisition->openings,
            'employment_type' => $requisition->employment_type?->value,
            'experience_min' => $requisition->experience_min !== null ? (float) $requisition->experience_min : null,
            'experience_max' => $requisition->experience_max !== null ? (float) $requisition->experience_max : null,
            'qualification' => $requisition->qualification,
            'skills' => array_values((array) $requisition->skills),
            'priority' => $requisition->priority?->value,
            'opening_date' => $requisition->opening_date?->toDateString(),
            'target_joining_date' => $requisition->target_joining_date?->toDateString(),
            'created_at' => $requisition->created_at?->toIso8601String(),
            'updated_at' => $requisition->updated_at?->toIso8601String(),
        ];
    }
}
