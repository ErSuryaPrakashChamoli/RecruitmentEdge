<?php

namespace App\Services\AI\Tools\OfferTools;

use App\Enums\AiRiskLevel;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AnalyzeOffersTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'analyze_offers';
    }

    public function description(): string
    {
        return 'Breakdown of offers by status (released, accepted, rejected, expired, withdrawn) and acceptance rate for a date range (default: last 90 days).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'start_date' => ['type' => 'string'],
                'end_date' => ['type' => 'string'],
            ],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'offers.manage';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $end = filled($arguments['end_date'] ?? null) ? Carbon::parse($arguments['end_date']) : Carbon::now();
        $start = filled($arguments['start_date'] ?? null) ? Carbon::parse($arguments['start_date']) : $end->copy()->subDays(90);
        $visibleIds = $this->visibleEmployeeIds($user);

        $offers = Offer::query()
            ->whereBetween('offer_date', [$start->toDateString(), $end->toDateString()])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas(
                'candidateApplication',
                fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds),
            ))
            ->get(['status']);

        $byStatus = $offers->countBy(fn (Offer $o) => $o->status->label());
        // Phase 8.4: status is an enum cast — compare cases, not strings (the string comparison never
        // matched, so the rate was always blank).
        $decided = $offers->filter(fn (Offer $offer) => in_array($offer->status, [OfferStatus::Accepted, OfferStatus::Rejected], true));
        $acceptanceRate = $decided->count() > 0
            ? round($decided->filter(fn (Offer $offer) => $offer->status === OfferStatus::Accepted)->count() / $decided->count() * 100, 1)
            : null;

        return ToolResult::ok(
            data: [
                'total_offers' => $offers->count(),
                'by_status' => $byStatus->toArray(),
                'acceptance_rate_pct' => $acceptanceRate,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
            summary: $acceptanceRate !== null
                ? "{$offers->count()} offer(s) in range; acceptance rate {$acceptanceRate}%."
                : "{$offers->count()} offer(s) in range; not enough decided offers for an acceptance rate.",
            type: 'kpi_card',
        );
    }
}
