<?php

namespace App\Services\Distribution;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\RecruitmentCampaign;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recruitment campaigns (Phase 5): lifecycle and links to existing requisitions/sources. Field
 * changes are audited by Auditable; relationship changes (pivots fire no model events) are audited
 * here explicitly.
 */
class RecruitmentCampaignService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, int|string>  $requisitionIds
     * @param  array<int, int|string>  $sourceIds
     */
    public function save(?RecruitmentCampaign $campaign, array $data, array $requisitionIds, array $sourceIds, ?Employee $actor = null): RecruitmentCampaign
    {
        if (filled($data['starts_on'] ?? null) && filled($data['ends_on'] ?? null) && $data['ends_on'] < $data['starts_on']) {
            throw new DomainException('A campaign cannot end before it starts.');
        }

        if (isset($data['budget']) && $data['budget'] !== null && (float) $data['budget'] < 0) {
            throw new DomainException('Budget cannot be negative.');
        }

        return DB::transaction(function () use ($campaign, $data, $requisitionIds, $sourceIds, $actor): RecruitmentCampaign {
            $attributes = array_intersect_key($data, array_flip(['name', 'description', 'owner_id', 'starts_on', 'ends_on', 'budget', 'target_hires', 'status']));

            if ($campaign === null) {
                $campaign = RecruitmentCampaign::query()->create([
                    ...$attributes,
                    'code' => $this->uniqueCode($data['code'] ?? $data['name']),
                    'owner_id' => $attributes['owner_id'] ?? $actor?->id,
                    'created_by' => $actor?->id,
                ]);
            } else {
                $campaign->update($attributes);
            }

            $before = ['requisitions' => $campaign->requisitions()->pluck('recruitment_requisitions.id')->sort()->values()->all(), 'sources' => $campaign->sources()->pluck('candidate_sources.id')->sort()->values()->all()];

            $campaign->requisitions()->sync(array_map('intval', $requisitionIds));
            $campaign->sources()->sync(array_map('intval', $sourceIds));

            $after = ['requisitions' => collect($requisitionIds)->map(fn ($id) => (int) $id)->sort()->values()->all(), 'sources' => collect($sourceIds)->map(fn ($id) => (int) $id)->sort()->values()->all()];

            if ($before !== $after) {
                AuditLog::record($campaign, 'campaign_links_updated', $before, $after);
            }

            return $campaign;
        });
    }

    /**
     * The running campaign a public tracking code refers to, if any.
     */
    public function resolveTrackingCode(?string $code): ?RecruitmentCampaign
    {
        if (blank($code)) {
            return null;
        }

        $campaign = RecruitmentCampaign::query()->where('code', strtoupper(trim($code)))->first();

        return $campaign?->isRunning() ? $campaign : null;
    }

    private function uniqueCode(string $source): string
    {
        $base = strtoupper(Str::limit(Str::slug($source, ''), 20, '')) ?: 'CAMPAIGN';
        $code = $base;
        $n = 2;

        while (RecruitmentCampaign::query()->where('code', $code)->exists()) {
            $code = "{$base}{$n}";
            $n++;
        }

        return $code;
    }
}
