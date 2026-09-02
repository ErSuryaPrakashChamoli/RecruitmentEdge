<?php

namespace App\Services\AI\Tools\OfferTools;

use App\Enums\AiRiskLevel;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Models\CandidateJoining;
use App\Models\Offer;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class AnalyzeJoiningConversionTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'analyze_joining_conversion';
    }

    public function description(): string
    {
        return 'Offer-accepted-to-actually-joined conversion rate for a date range (default: last 90 days) — how many accepted offers turn into real joins vs no-shows/dropouts.';
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
        return 'joining.confirm';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $end = filled($arguments['end_date'] ?? null) ? Carbon::parse($arguments['end_date']) : Carbon::now();
        $start = filled($arguments['start_date'] ?? null) ? Carbon::parse($arguments['start_date']) : $end->copy()->subDays(90);
        $visibleIds = $this->visibleEmployeeIds($user);

        $acceptedOfferIds = Offer::query()
            ->where('status', OfferStatus::Accepted)
            ->whereBetween('accepted_at', [$start, $end])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas(
                'candidateApplication',
                fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds),
            ))
            ->pluck('id');

        $joinings = CandidateJoining::query()->whereIn('offer_id', $acceptedOfferIds)->get(['status']);
        $joined = $joinings->where('status', JoiningStatus::Joined)->count();
        $rate = $acceptedOfferIds->count() > 0 ? round($joined / $acceptedOfferIds->count() * 100, 1) : null;

        return ToolResult::ok(
            data: [
                'accepted_offers' => $acceptedOfferIds->count(),
                'actually_joined' => $joined,
                'conversion_rate_pct' => $rate,
                'by_status' => $joinings->countBy(fn (CandidateJoining $j) => $j->status->label())->toArray(),
            ],
            summary: $rate !== null
                ? "{$rate}% of accepted offers converted to actual joins ({$joined} of {$acceptedOfferIds->count()})."
                : 'No accepted offers in this range.',
            type: 'kpi_card',
        );
    }
}
