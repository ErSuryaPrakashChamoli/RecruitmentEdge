<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\UpdateProfileRequest;
use App\Models\CandidatePortalAccount;
use App\Services\CandidatePortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        /** @var CandidatePortalAccount $account */
        $account = $request->user('candidate');

        return view('portal.profile', ['account' => $account, 'candidate' => $account->candidate]);
    }

    public function update(UpdateProfileRequest $request, CandidatePortalService $portal): RedirectResponse
    {
        /** @var CandidatePortalAccount $account */
        $account = $request->user('candidate');

        $changed = $portal->updateProfile($account, $request->validated());

        return back()->with('status', $changed === [] ? 'Nothing changed.' : 'Your profile has been updated.');
    }
}
