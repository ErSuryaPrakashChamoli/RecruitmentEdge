<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CandidatePortalAccount;
use App\Services\CandidatePortalService;
use App\Services\CandidateTimelineService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, CandidatePortalService $portal, CandidateTimelineService $timeline): View
    {
        /** @var CandidatePortalAccount $account */
        $account = $request->user('candidate');

        return view('portal.dashboard', [
            'account' => $account,
            'applications' => $portal->applicationsFor($account),
            'invitations' => $portal->openInvitationsFor($account),
            'bookings' => $portal->activeBookingsFor($account),
            'timeline' => $timeline->forPortal($account->candidate, 15),
            'messages' => $portal->messagesFor($account),
            'portal' => $portal,
        ]);
    }
}
