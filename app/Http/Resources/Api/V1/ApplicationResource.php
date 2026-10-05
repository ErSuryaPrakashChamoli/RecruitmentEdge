<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CandidateApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SaaS-6 API v1: an application — where it stands, not why (no remarks, rejection or dropout
 * reasons).
 *
 * @property-read CandidateApplication $resource
 */
class ApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $application = $this->resource;

        return [
            'id' => $application->getKey(),
            'code' => $application->application_code,
            'candidate_id' => $application->candidate_id,
            'requisition_id' => $application->requisition_id,
            'job_posting_id' => $application->job_posting_id,
            'stage' => $application->current_stage?->value,
            'status' => $application->status?->value,
            'origin_channel' => $application->origin_channel,
            'application_date' => $application->application_date?->toDateString(),
            'created_at' => $application->created_at?->toIso8601String(),
            'updated_at' => $application->updated_at?->toIso8601String(),
        ];
    }
}
