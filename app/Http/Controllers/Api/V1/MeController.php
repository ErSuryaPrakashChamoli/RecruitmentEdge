<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SaaS-6: which credential this is, whom it acts for, in which organisation, with which scopes.
 */
class MeController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $principal = $this->principal($request);

        return response()->json(['data' => [
            'credential' => [
                'name' => $principal->credential->name,
                'key' => $principal->credential->displayKey(),
                'scopes' => array_values((array) $principal->credential->scopes),
                'expires_at' => $principal->credential->expires_at?->toIso8601String(),
            ],
            'acting_as' => ['id' => $principal->owner->getKey(), 'name' => $principal->owner->name],
            'organisation' => ['slug' => $principal->tenant->slug, 'name' => $principal->tenant->name],
        ]]);
    }
}
