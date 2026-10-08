<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Filament\Resources\Candidates\CandidateResource;
use App\Models\Candidate;
use App\Services\CandidateDuplicateDetector;
use App\Services\DuplicateCandidateMatch;
use App\Services\SequenceCodeGenerator;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Collection;

/**
 * Creating a candidate runs deterministic duplicate detection first (Phase 4). A strong match
 * halts creation and shows the existing record(s) with masked contact details; the user either
 * opens the existing candidate or — with candidates.override-duplicate — gives a justification,
 * which is audited via CandidateDuplicateDetector::recordOverride(). Nothing is ever merged.
 */
class CreateCandidate extends CreateRecord
{
    protected static string $resource = CandidateResource::class;

    /**
     * Masked summaries of the strong matches found on the last create attempt.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $duplicateMatches = [];

    /**
     * @var Collection<int, DuplicateCandidateMatch>|null
     */
    protected ?Collection $overriddenMatches = null;

    protected ?string $overrideJustification = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $justification = trim((string) ($data['duplicate_override_reason'] ?? ''));
        unset($data['duplicate_override_reason']);

        $matches = app(CandidateDuplicateDetector::class)->strongMatches($data);

        if ($matches->isNotEmpty()) {
            $canOverride = (bool) Filament::auth()->user()?->can('candidates.override-duplicate');

            if (! $canOverride || $justification === '') {
                $this->haltForDuplicates($matches, $canOverride);
            }

            $this->overriddenMatches = $matches;
            $this->overrideJustification = $justification;
        }

        $data['candidate_code'] = app(SequenceCodeGenerator::class)->next('CAND');
        $data['created_by'] = Filament::auth()->user()?->employee_id;

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->overriddenMatches === null) {
            return;
        }

        /** @var Candidate $candidate */
        $candidate = $this->getRecord();

        app(CandidateDuplicateDetector::class)->recordOverride(
            $candidate,
            $this->overriddenMatches,
            (string) $this->overrideJustification,
            Filament::auth()->user()?->employee,
        );
    }

    /**
     * @param  Collection<int, DuplicateCandidateMatch>  $matches
     */
    private function haltForDuplicates(Collection $matches, bool $canOverride): never
    {
        $this->duplicateMatches = $matches->map(fn (DuplicateCandidateMatch $match) => $match->maskedSummary())->all();

        $user = Filament::auth()->user();

        Notification::make()
            ->title('Potential duplicate detected')
            ->body($canOverride
                ? 'Use the existing candidate, or enter a justification below to create a new one.'
                : 'Use the existing candidate — creating a duplicate needs a user with permission to override.')
            ->warning()
            ->persistent()
            ->actions($matches->take(3)
                ->filter(fn (DuplicateCandidateMatch $match): bool => (bool) $user?->can('view', $match->candidate))
                ->map(fn (DuplicateCandidateMatch $match) => Action::make('review'.$match->candidate->id)
                    ->label("Open {$match->candidate->candidate_code}")
                    ->url(CandidateResource::getUrl('view', ['record' => $match->candidate])))
                ->values()
                ->all())
            ->send();

        throw new Halt;
    }
}
