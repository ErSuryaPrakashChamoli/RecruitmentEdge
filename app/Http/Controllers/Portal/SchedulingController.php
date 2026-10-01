<?php

namespace App\Http\Controllers\Portal;

use App\Enums\SchedulingChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\BookSlotRequest;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidatePortalAccount;
use App\Models\InterviewAvailabilitySlot;
use App\Models\InterviewSchedulingInvitation;
use App\Models\InterviewSlotBooking;
use App\Services\InterviewSchedulingService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Candidate self-scheduling pages. Reachable two ways: a temporary signed link (no sign-in
 * needed — the signature is the credential) or by the signed-in portal candidate who owns the
 * invitation/booking. Anyone else gets a 404. Every form posts to a freshly signed URL so the
 * signed-link flow works end to end without a session.
 */
class SchedulingController extends Controller
{
    public const int FORM_LINK_MINUTES = 30;

    public function show(Request $request, string $invitation, InterviewSchedulingService $scheduling): View
    {
        $invitation = $this->authorizedInvitation($request, $invitation);
        $application = $invitation->candidateApplication;

        return view('portal.schedule.show', [
            'invitation' => $invitation,
            'application' => $application,
            'booking' => $scheduling->activeBookingFor($application),
            'slots' => $invitation->isOpen() ? $scheduling->availableSlotsFor($invitation) : collect(),
            'bookUrl' => URL::temporarySignedRoute('portal.schedule.book', now()->addMinutes(self::FORM_LINK_MINUTES), ['invitation' => $invitation->public_id]),
        ]);
    }

    public function book(BookSlotRequest $request, string $invitation, InterviewSchedulingService $scheduling): RedirectResponse
    {
        $invitation = $this->authorizedInvitation($request, $invitation);
        $slot = InterviewAvailabilitySlot::query()->where('public_id', $request->string('slot'))->firstOrFail();
        $account = $request->user('candidate');

        try {
            $booking = AuditLog::asCandidate($this->candidateActor($request, $invitation->candidateApplication->candidate_id), fn () => $scheduling->book(
                $invitation,
                $slot,
                $account !== null ? SchedulingChannel::CandidatePortal : SchedulingChannel::SignedLink,
                $account,
                $request->input('note'),
            ));
        } catch (DomainException $e) {
            return back()->withErrors(['slot' => $e->getMessage()]);
        }

        return redirect()->to($this->bookingUrl($booking))->with('status', 'Your interview is booked.');
    }

    public function showBooking(Request $request, string $booking, InterviewSchedulingService $scheduling): View
    {
        $booking = $this->authorizedBooking($request, $booking);
        $invitation = $booking->invitation_id !== null ? InterviewSchedulingInvitation::query()->find($booking->invitation_id) : null;

        return view('portal.schedule.booking', [
            'booking' => $booking,
            'slots' => $booking->isActive() && $invitation !== null ? $scheduling->availableSlotsFor($invitation, $booking->slot) : collect(),
            'rescheduleUrl' => URL::temporarySignedRoute('portal.bookings.reschedule', now()->addMinutes(self::FORM_LINK_MINUTES), ['booking' => $booking->public_id]),
            'cancelUrl' => URL::temporarySignedRoute('portal.bookings.cancel', now()->addMinutes(self::FORM_LINK_MINUTES), ['booking' => $booking->public_id]),
        ]);
    }

    public function reschedule(BookSlotRequest $request, string $booking, InterviewSchedulingService $scheduling): RedirectResponse
    {
        $booking = $this->authorizedBooking($request, $booking);
        $slot = InterviewAvailabilitySlot::query()->where('public_id', $request->string('slot'))->firstOrFail();

        try {
            $new = AuditLog::asCandidate($this->candidateActor($request, $booking->candidate_id), fn () => $scheduling->reschedule($booking, $slot, $request->user('candidate'), $request->input('reason')));
        } catch (DomainException $e) {
            return back()->withErrors(['slot' => $e->getMessage()]);
        }

        return redirect()->to($this->bookingUrl($new))->with('status', 'Your interview has been moved.');
    }

    public function cancel(Request $request, string $booking, InterviewSchedulingService $scheduling): RedirectResponse
    {
        $booking = $this->authorizedBooking($request, $booking);
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        try {
            AuditLog::asCandidate($this->candidateActor($request, $booking->candidate_id), fn () => $scheduling->cancelBooking($booking, $request->string('reason')->toString(), $request->user('candidate')));
        } catch (DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->to($this->bookingUrl($booking))->with('status', 'Your interview booking was cancelled.');
    }

    private function bookingUrl(InterviewSlotBooking $booking): string
    {
        return URL::temporarySignedRoute('portal.bookings.show', now()->addDays(14), ['booking' => $booking->public_id]);
    }

    private function authorizedInvitation(Request $request, string $publicId): InterviewSchedulingInvitation
    {
        $invitation = InterviewSchedulingInvitation::query()->where('public_id', $publicId)->with('candidateApplication.requisition.designation')->firstOrFail();

        abort_unless($request->hasValidSignature() || $this->ownsApplicationOf($request, $invitation->candidateApplication->candidate_id), 404);

        return $invitation;
    }

    private function authorizedBooking(Request $request, string $publicId): InterviewSlotBooking
    {
        $booking = InterviewSlotBooking::query()->where('public_id', $publicId)->with(['slot.interviewer', 'candidateApplication.requisition.designation'])->firstOrFail();

        abort_unless($request->hasValidSignature() || $this->ownsApplicationOf($request, $booking->candidate_id), 404);

        return $booking;
    }

    /**
     * Phase 8.8 (D8.8-001): who the audit trail records for a self-scheduling action — resolved on
     * the server from the invitation or booking the signature (or the owner's session) authorised:
     * the signed-in owner, else that candidate's portal account, else the candidate record. Never
     * a staff user, whatever else the browser is signed in to.
     */
    private function candidateActor(Request $request, int $candidateId): Model
    {
        if ($this->ownsApplicationOf($request, $candidateId)) {
            return $request->user('candidate');
        }

        return CandidatePortalAccount::query()->where('candidate_id', $candidateId)->first()
            ?? Candidate::query()->findOrFail($candidateId);
    }

    private function ownsApplicationOf(Request $request, int $candidateId): bool
    {
        /** @var CandidatePortalAccount|null $account */
        $account = $request->user('candidate');

        return $account !== null && $account->is_active && $account->candidate_id === $candidateId;
    }
}
