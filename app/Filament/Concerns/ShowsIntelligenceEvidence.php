<?php

namespace App\Filament\Concerns;

use App\Services\Intelligence\EvidenceLookup;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;

/**
 * The "Why?" drill-down shared by every EDGE Intelligence screen: a slide-over listing the evidence
 * behind one value — what was observed, its source, when, which generator produced it, whether it
 * is AI-derived (and its verification) — loaded through EvidenceLookup's authorization.
 */
trait ShowsIntelligenceEvidence
{
    public function evidenceAction(): Action
    {
        return Action::make('evidence')
            ->label('Why?')
            ->icon('heroicon-o-magnifying-glass-circle')
            ->link()
            ->size('sm')
            ->slideOver()
            ->modalWidth(Width::TwoExtraLarge)
            ->modalHeading(fn (array $arguments) => $arguments['heading'] ?? 'Evidence')
            ->modalContent(fn (array $arguments) => view('filament.intelligence.evidence', [
                'rows' => app(EvidenceLookup::class)->for(auth()->user(), (string) ($arguments['owner'] ?? ''), (int) ($arguments['id'] ?? 0), $arguments['subject'] ?? null),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }
}
