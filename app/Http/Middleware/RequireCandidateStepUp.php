<?php

namespace App\Http\Middleware;

use App\Models\CandidatePortalAccount;
use App\Services\CandidateStepUpService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 8.8 (D8.8-001): `candidate.step-up:{purpose},{minutes?}` — a candidate route that needs a
 * recent email one-time code. Without one, the candidate is sent to the verification page and
 * returned here afterwards. Use after `auth:candidate`. No route requires it yet.
 */
class RequireCandidateStepUp
{
    public const string INTENDED_KEY = 'portal.step_up_intended';

    public function __construct(private readonly CandidateStepUpService $stepUp) {}

    public function handle(Request $request, Closure $next, string $purpose = 'sensitive_action', ?string $freshForMinutes = null): Response
    {
        /** @var CandidatePortalAccount|null $account */
        $account = $request->user(UseCandidateSessionContext::CANDIDATE_GUARD);

        abort_if($account === null, 403);

        if ($this->stepUp->isSatisfied($account, $purpose, $request->session(), $freshForMinutes !== null ? (int) $freshForMinutes : null)) {
            return $next($request);
        }

        $request->session()->put(self::INTENDED_KEY, ['purpose' => $purpose, 'url' => $request->isMethod('GET') ? $request->fullUrl() : route('portal.dashboard')]);

        return redirect()->route('portal.step-up.show');
    }
}
