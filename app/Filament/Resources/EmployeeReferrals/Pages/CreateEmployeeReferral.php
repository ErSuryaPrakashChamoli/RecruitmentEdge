<?php

namespace App\Filament\Resources\EmployeeReferrals\Pages;

use App\Enums\RequisitionStatus;
use App\Filament\Resources\EmployeeReferrals\EmployeeReferralResource;
use App\Models\Candidate;
use App\Models\RecruitmentRequisition;
use App\Services\CandidateDuplicateDetector;
use App\Services\DuplicateCandidateFoundException;
use App\Services\DuplicateCandidateMatch;
use App\Services\ReferralService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * "Submit a referral". Runs duplicate detection through ReferralService: when the candidate
 * already exists, the employee is shown masked details and refers the existing candidate (the
 * choice is re-validated against the detector's matches, so a tampered id can't reference an
 * arbitrary candidate). Creating a new record over a match needs candidates.override-duplicate
 * and a justification, which is audited.
 */
class CreateEmployeeReferral extends CreateRecord
{
    protected static string $resource = EmployeeReferralResource::class;

    protected static ?string $title = 'Submit a referral';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $duplicateMatches = [];

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $referrer = auth()->user()?->employee;
        abort_if($referrer === null, 403);

        $requisition = filled($data['requisition_id'] ?? null)
            ? RecruitmentRequisition::query()->where('status', RequisitionStatus::Open)->findOrFail($data['requisition_id'])
            : null;

        $choice = $data['existing_candidate_choice'] ?? null;
        $existing = null;
        $justification = null;

        if ($choice === 'new') {
            abort_unless((bool) auth()->user()?->can('candidates.override-duplicate'), 403);
            $justification = $data['duplicate_override_reason'] ?? null;
        } elseif (filled($choice)) {
            $existing = app(CandidateDuplicateDetector::class)->strongMatches($data)
                ->map(fn (DuplicateCandidateMatch $match): Candidate => $match->candidate)
                ->firstWhere('id', (int) $choice);

            abort_if($existing === null, 422);
        }

        try {
            return app(ReferralService::class)->submit(
                $referrer,
                $data,
                ['relationship' => $data['relationship'], 'notes' => $data['notes'] ?? null],
                $requisition,
                $existing,
                $justification,
            );
        } catch (DuplicateCandidateFoundException $e) {
            $this->duplicateMatches = $e->matches
                ->map(fn (DuplicateCandidateMatch $match) => ['id' => $match->candidate->id, ...$match->maskedSummary()])
                ->all();

            Notification::make()
                ->title('This candidate is already in our system')
                ->body('Choose the existing candidate below to refer them.')
                ->warning()
                ->persistent()
                ->send();

            throw new Halt;
        } catch (DomainException $e) {
            Notification::make()->title('Referral not submitted')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Referral submitted — thank you!';
    }
}
