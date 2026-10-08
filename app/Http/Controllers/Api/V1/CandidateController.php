<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\CandidateResource;
use App\Models\Candidate;
use App\Services\CandidateIdentityNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * SaaS-6: candidates the credential's owner may see (candidates.viewAny, Candidate::visibleTo).
 */
class CandidateController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Candidate::class);

        return $this->list($request, Candidate::query()->visibleTo($this->principal($request)->owner)->with('source'), CandidateResource::class, [
            'email' => ['sometimes', 'string', 'max:255'],
            'created_since' => ['sometimes', 'date'],
        ], [
            'email' => fn ($query, $value) => $query->where('email_normalized', CandidateIdentityNormalizer::email((string) $value)),
            'created_since' => fn ($query, $value) => $query->where('candidates.created_at', '>=', $value),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $candidate = Candidate::query()->visibleTo($this->principal($request)->owner)->with('source')->findOrFail($id);
        Gate::authorize('view', $candidate);

        return $this->one($request, new CandidateResource($candidate));
    }
}
