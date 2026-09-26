<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\SetPasswordRequest;
use App\Models\CandidatePortalAccount;
use App\Services\CandidatePortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Setting a password through the emailed temporary signed link (route middleware `signed`). The
 * link also carries a fingerprint of the current password, so it is single-use.
 */
class PasswordController extends Controller
{
    public function edit(Request $request, CandidatePortalAccount $account, CandidatePortalService $portal): View
    {
        $this->ensureLinkIsCurrent($request, $account, $portal);

        return view('portal.auth.set-password', ['account' => $account, 'action' => $request->fullUrl()]);
    }

    public function update(SetPasswordRequest $request, CandidatePortalAccount $account, CandidatePortalService $portal): RedirectResponse
    {
        $this->ensureLinkIsCurrent($request, $account, $portal);

        $portal->setPassword($account, $request->string('password')->toString());

        Auth::guard('candidate')->login($account);
        $request->session()->regenerate();
        $portal->recordLogin($account);

        return redirect()->route('portal.dashboard')->with('status', 'Your password is set. Welcome!');
    }

    public function forgot(): View
    {
        return view('portal.auth.forgot-password');
    }

    public function sendLink(Request $request, CandidatePortalService $portal): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        $portal->sendPasswordLink($request->string('email')->toString());

        return back()->with('status', 'If that email has portal access, a link to set your password is on its way.');
    }

    private function ensureLinkIsCurrent(Request $request, CandidatePortalAccount $account, CandidatePortalService $portal): void
    {
        abort_unless($account->is_active && hash_equals($portal->passwordFingerprint($account), (string) $request->query('k')), 403, 'This link has expired or was already used.');
    }
}
