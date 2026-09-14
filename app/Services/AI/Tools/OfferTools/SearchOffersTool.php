<?php

namespace App\Services\AI\Tools\OfferTools;

use App\Enums\AiRiskLevel;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class SearchOffersTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'search_offers';
    }

    public function description(): string
    {
        return 'List individual offers by status and/or offer date range, with candidate, CTC, expiry and expected joining date — scoped to what the current user may see. Use analyze_offers for aggregate rates.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'One of: '.implode(', ', array_map(fn (OfferStatus $status) => $status->value, OfferStatus::cases()))],
                'start_date' => ['type' => 'string', 'description' => 'Offer date on/after (YYYY-MM-DD)'],
                'end_date' => ['type' => 'string', 'description' => 'Offer date on/before (YYYY-MM-DD)'],
                'limit' => ['type' => 'integer', 'description' => 'Max results, default 20'],
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
        $status = filled($arguments['status'] ?? null) ? OfferStatus::tryFrom((string) $arguments['status']) : null;

        if (filled($arguments['status'] ?? null) && $status === null) {
            return ToolResult::fail('Unknown offer status.');
        }

        $limit = max(1, min((int) ($arguments['limit'] ?? 20), 50));
        $visibleIds = $this->visibleEmployeeIds($user);

        $offers = Offer::query()
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when(filled($arguments['start_date'] ?? null), fn (Builder $q) => $q->whereDate('offer_date', '>=', CarbonImmutable::parse($arguments['start_date'])->toDateString()))
            ->when(filled($arguments['end_date'] ?? null), fn (Builder $q) => $q->whereDate('offer_date', '<=', CarbonImmutable::parse($arguments['end_date'])->toDateString()))
            ->with(['candidateApplication.candidate:id,full_name', 'designation:id,name'])
            ->latest('offer_date')
            ->limit($limit)
            ->get();

        $rows = $offers->map(fn (Offer $offer) => [
            'offer_id' => $offer->id,
            'offer_code' => $offer->offer_code,
            'application_id' => $offer->candidate_application_id,
            'candidate' => $offer->candidateApplication?->candidate?->full_name,
            'designation' => $offer->designation?->name,
            'offered_ctc' => $offer->offered_ctc,
            'status' => $offer->status->label(),
            'offer_date' => $offer->offer_date?->toDateString(),
            'offer_expiry' => $offer->offer_expiry?->toDateString(),
            'expected_joining_date' => $offer->expected_joining_date?->toDateString(),
        ]);

        return ToolResult::ok(
            data: ['offers' => $rows->toArray()],
            summary: "Found {$rows->count()} offer(s).",
            type: 'offer_list',
        );
    }
}
