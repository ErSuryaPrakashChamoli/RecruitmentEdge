<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SaaS-6 API v1: a candidate — identity and contact details an integration needs; never salaries,
 * remarks, source notes, file paths, referral or duplicate-detection internals.
 *
 * @property-read Candidate $resource
 */
class CandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $candidate = $this->resource;

        return [
            'id' => $candidate->getKey(),
            'code' => $candidate->candidate_code,
            'full_name' => $candidate->full_name,
            'email' => $candidate->email,
            'mobile' => $candidate->mobile,
            'current_city' => $candidate->current_city,
            'location' => $candidate->location,
            'qualification' => $candidate->qualification,
            'total_experience' => $candidate->total_experience !== null ? (float) $candidate->total_experience : null,
            'current_company' => $candidate->current_company,
            'current_designation' => $candidate->current_designation,
            'notice_period_days' => $candidate->notice_period_days,
            'skills' => array_values((array) $candidate->skills),
            'source' => $candidate->source !== null ? ['id' => $candidate->source->getKey(), 'name' => $candidate->source->name] : null,
            'created_at' => $candidate->created_at?->toIso8601String(),
            'updated_at' => $candidate->updated_at?->toIso8601String(),
        ];
    }
}
