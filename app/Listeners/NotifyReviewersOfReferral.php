<?php

namespace App\Listeners;

use App\Events\ReferralSubmitted;
use App\Filament\Resources\EmployeeReferrals\EmployeeReferralResource;
use App\Models\Employee;
use App\Services\NotificationDispatchService;

/**
 * Tells the requisition's assigned recruiters and manager that a referral arrived (Phase 6's
 * Notification Center will consume the same event). General referrals have no owner yet and
 * surface in the Referrals review queue instead.
 */
class NotifyReviewersOfReferral
{
    public function __construct(private readonly NotificationDispatchService $notifications) {}

    public function handle(ReferralSubmitted $event): void
    {
        $referral = $event->referral->loadMissing('requisition.recruiters.user', 'requisition.manager.user', 'candidate', 'referrer');
        $requisition = $referral->requisition;

        if ($requisition === null) {
            return;
        }

        collect($requisition->recruiters->all())
            ->push($requisition->manager)
            ->filter()
            ->map(fn (Employee $employee) => $employee->user)
            ->filter()
            ->unique('id')
            ->each(fn ($user) => $this->notifications->alert(
                $user,
                'Referrals',
                'New employee referral',
                "{$referral->referrer->fullName()} referred {$referral->candidate->full_name} for {$requisition->code}.",
                'info',
                EmployeeReferralResource::getUrl('view', ['record' => $referral]),
                "referral-submitted-{$referral->id}-{$user->id}",
            ));
    }
}
