<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\ApplicationResource;
use App\Services\Api\ApplicantIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * SaaS-6: submits an applicant to a live job posting (applications:write, Idempotency-Key). 201 with
 * the new application, or 202 "held" when the contact details match an existing candidate (the
 * career site's rule: nothing is written to that candidate; the recruiter follows up).
 */
class ApplicationIntakeController extends ApiController
{
    public function __invoke(Request $request, int $id, ApplicantIntakeService $intake): JsonResponse
    {
        $data = Validator::make((array) $request->json()->all(), ApplicantIntakeService::rules())->validate();
        $result = $intake->viaCredential($this->principal($request), $id, $data);

        if ($result['outcome'] === 'held' || $result['application'] === null) {
            return response()->json(['data' => ['outcome' => 'held', 'application' => null]], 202);
        }

        return response()->json(['data' => ['outcome' => 'received', 'application' => (new ApplicationResource($result['application']))->resolve($request)]], 201);
    }
}
