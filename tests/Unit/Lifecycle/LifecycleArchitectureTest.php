<?php

use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Concerns\GuardsLifecycleAttributes;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\Offer;
use App\Models\RecruitmentRequisition;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Phase 8.3: a domain event may only be heard once the fact it announces is committed — no
 * listener (automation, communication, calendar, Outcome Loop, Hiring Memory) can act on a change
 * that is later rolled back.
 */
arch('every domain event dispatches after the transaction commits')
    ->expect('App\Events')
    ->toImplement(ShouldDispatchAfterCommit::class);

arch('every model holding a hiring fact guards its lifecycle attributes')
    ->expect([
        CandidateApplication::class,
        Interview::class,
        InterviewFeedback::class,
        Offer::class,
        CandidateJoining::class,
        RecruitmentRequisition::class,
    ])
    ->toUseTrait(GuardsLifecycleAttributes::class);

test('only the authoritative lifecycle services open the lifecycle guard', function (): void {
    $root = dirname(__DIR__, 3);
    $allowed = [
        'app/Services/StageTransitionService.php',
        'app/Services/ApplicationAssignmentService.php',
        'app/Services/InterviewService.php',
        'app/Services/InterviewFeedbackService.php',
        'app/Services/OfferService.php',
        'app/Services/CandidateJoiningService.php',
        'app/Services/RequisitionApprovalService.php',
        'app/Services/Lifecycle/LifecycleGuard.php',
        // Phase 8.4: identity and employment state.
        'app/Services/Identity/StaffAccessService.php',
        'app/Services/Identity/EmployeeLifecycleService.php',
        'app/Services/Identity/IdentityProvisioningService.php',
        'app/Services/Identity/HierarchyIntegrityService.php',
        // SaaS-2: memberships and invitations.
        'app/Services/Identity/TenantInvitationService.php',
        'app/Services/Platform/PlatformIdentityService.php',
    ];

    $files = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->map(fn (SplFileInfo $file) => str_replace($root.'/', '', $file->getPathname()));

    foreach ($files as $path) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        expect(str_contains((string) file_get_contents($root.'/'.$path), 'LifecycleGuard::allow'))->toBeFalse("{$path} opens the lifecycle guard outside an authoritative service");
    }
});
