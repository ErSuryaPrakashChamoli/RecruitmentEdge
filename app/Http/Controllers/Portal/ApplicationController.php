<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\RescheduleRequestRequest;
use App\Models\CandidatePortalAccount;
use App\Services\CandidatePortalService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A candidate's own application and its interviews. Applications are looked up by reference
 * within the signed-in candidate's records only (CandidatePortalService::findApplication()).
 */
class ApplicationController extends Controller
{
    public function show(Request $request, string $application, CandidatePortalService $portal): View
    {
        /** @var CandidatePortalAccount $account */
        $account = $request->user('candidate');

        return view('portal.applications.show', [
            'application' => $portal->findApplication($account, $application),
            'portal' => $portal,
            'bookings' => $portal->activeBookingsFor($account)->keyBy('candidate_application_id'),
        ]);
    }

    public function confirmInterview(Request $request, string $application, int $round, CandidatePortalService $portal): RedirectResponse
    {
        /** @var CandidatePortalAccount $account */
        $account = $request->user('candidate');
        $interview = $portal->findInterview($portal->findApplication($account, $application), $round);

        try {
            $portal->confirmInterview($account, $interview);
        } catch (DomainException $e) {
            return back()->withErrors(['interview' => $e->getMessage()]);
        }

        return back()->with('status', 'Thanks — your interview is confirmed.');
    }

    public function requestReschedule(RescheduleRequestRequest $request, string $application, int $round, CandidatePortalService $portal): RedirectResponse
    {
        /** @var CandidatePortalAccount $account */
        $account = $request->user('candidate');
        $interview = $portal->findInterview($portal->findApplication($account, $application), $round);

        try {
            $portal->requestReschedule($account, $interview, $request->string('reason')->toString());
        } catch (DomainException $e) {
            return back()->withErrors(['interview' => $e->getMessage()]);
        }

        return back()->with('status', 'Your request was sent to your recruiter, who will get back to you with a new time.');
    }
}
