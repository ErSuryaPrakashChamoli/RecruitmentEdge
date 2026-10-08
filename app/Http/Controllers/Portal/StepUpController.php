<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireCandidateStepUp;
use App\Models\CandidatePortalAccount;
use App\Services\CandidateStepUpService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 8.8 (D8.8-001): the email one-time-code page for a signed-in candidate. All rules live in
 * CandidateStepUpService; the purpose comes from the server-side session set by the route that
 * asked for the step-up, never from the form.
 */
class StepUpController extends Controller
{
    public function show(Request $request): View
    {
        return view('portal.auth.step-up', ['email' => $this->account($request)->email]);
    }

    public function send(Request $request, CandidateStepUpService $stepUp): RedirectResponse
    {
        try {
            $stepUp->issue($this->account($request), $this->purpose($request));
        } catch (DomainException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('status', 'We emailed a 6-digit code to your portal email address. It expires in '.config('portal.step_up.code_ttl_minutes').' minutes.');
    }

    public function verify(Request $request, CandidateStepUpService $stepUp): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], ['code.regex' => 'Enter the 6-digit code from the email.']);

        if (! $stepUp->verify($this->account($request), $this->purpose($request), $request->string('code')->toString(), $request->session())) {
            return back()->withErrors(['code' => 'That code is not valid or has expired. Request a new code if needed.']);
        }

        $intended = $request->session()->pull(RequireCandidateStepUp::INTENDED_KEY);

        return redirect()->to(is_array($intended) && is_string($intended['url'] ?? null) ? $intended['url'] : route('portal.dashboard'))->with('status', 'Verified.');
    }

    private function account(Request $request): CandidatePortalAccount
    {
        return $request->user('candidate');
    }

    private function purpose(Request $request): string
    {
        $intended = $request->session()->get(RequireCandidateStepUp::INTENDED_KEY);

        return is_array($intended) && is_string($intended['purpose'] ?? null) ? $intended['purpose'] : 'sensitive_action';
    }
}
