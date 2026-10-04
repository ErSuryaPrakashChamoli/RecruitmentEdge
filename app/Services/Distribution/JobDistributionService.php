<?php

namespace App\Services\Distribution;

use App\Enums\DistributionStatus;
use App\Enums\Entitlement;
use App\Enums\JobPostingStatus;
use App\Enums\RequisitionStatus;
use App\Jobs\PublishJobDistributionJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\JobDistribution;
use App\Models\JobPosting;
use App\Models\RecruitmentRequisition;
use App\Services\Entitlements\EntitlementService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Job Distribution Engine (Phase 5): Approved (Open) requisition → posting → channels
 * (career site, XML feed, job boards) → normalized results. Owns every posting status and
 * distribution row change; connector calls run in PublishJobDistributionJob (queued, retried).
 *
 * Rules: only an Open (approved) requisition can be published; a channel already published is
 * never published twice (the distribution row is unique per channel); every publish/unpublish/
 * error is audited.
 */
class JobDistributionService
{
    public const string CAREER_SITE = 'career_site';

    public function __construct(private readonly JobBoardRegistry $boards) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function savePosting(RecruitmentRequisition $requisition, array $data, ?Employee $actor = null): JobPosting
    {
        $posting = $requisition->jobPosting ?? new JobPosting(['requisition_id' => $requisition->id, 'created_by' => $actor?->id]);

        $posting->fill([
            ...array_intersect_key($data, array_flip(['title', 'summary', 'description', 'show_salary', 'closes_at'])),
            'updated_by' => $actor?->id,
        ]);

        if (! $posting->exists) {
            $posting->public_slug = $this->uniqueSlug(($data['title'] ?? $requisition->designation?->name ?? 'job').'-'.$requisition->code);
        }

        $posting->save();

        if ($posting->status === JobPostingStatus::Published) {
            $posting->distributions()->where('status', DistributionStatus::Published)->get()
                ->each(fn (JobDistribution $d) => PublishJobDistributionJob::dispatch($d->id, 'update')->afterCommit());
        }

        return $posting;
    }

    /**
     * Publishes the posting to $channels. Returns the distribution rows queued (channels already
     * published are skipped, never duplicated).
     *
     * @param  array<int, string>  $channels
     * @return Collection<int, JobDistribution>
     */
    public function publish(JobPosting $posting, array $channels, ?Employee $actor = null): Collection
    {
        $posting->loadMissing('requisition');

        if ($posting->requisition?->status !== RequisitionStatus::Open) {
            throw new DomainException('Only an approved, Open requisition can be published (current status: '.($posting->requisition?->status?->label() ?? 'unknown').').');
        }

        if ($posting->closes_at !== null && $posting->closes_at->endOfDay()->isPast()) {
            throw new DomainException('The posting\'s closing date has passed.');
        }

        if ($channels === []) {
            throw new DomainException('Choose at least one channel.');
        }

        // SaaS-3: external job boards need them in the tenant's plan; the tenant's own careers site
        // is always included.
        if (array_diff(array_unique($channels), [self::CAREER_SITE]) !== []) {
            app(EntitlementService::class)->require(Entitlement::DistributionJobBoards);
        }

        $connectors = collect($channels)->unique()->mapWithKeys(fn (string $key) => [$key => $this->boards->find($key) ?? throw new DomainException("Unknown channel \"{$key}\".")]);

        $problems = $connectors->flatMap(fn (JobBoardConnector $c, string $key) => array_map(fn (string $p) => "{$c->label()}: {$p}", $c->validate($posting)))->all();

        if ($problems !== []) {
            throw new DomainException(implode(' ', $problems));
        }

        return DB::transaction(function () use ($posting, $connectors, $actor): Collection {
            $posting->forceFill(['status' => JobPostingStatus::Published, 'published_at' => $posting->published_at ?? now()])->save();

            $queued = $connectors->keys()->map(function (string $channel) use ($posting): ?JobDistribution {
                $distribution = JobDistribution::query()->lockForUpdate()->firstOrCreate(['job_posting_id' => $posting->id, 'channel' => $channel]);

                if ($distribution->status === DistributionStatus::Published) {
                    return null;
                }

                $distribution->forceFill(['status' => DistributionStatus::Pending, 'last_error' => null])->save();
                PublishJobDistributionJob::dispatch($distribution->id, 'publish')->afterCommit();

                return $distribution;
            })->filter()->values();

            AuditLog::record($posting, 'job_published', null, ['channels' => $connectors->keys()->all(), 'queued' => $queued->pluck('channel')->all(), 'actor_employee_id' => $actor?->id]);

            return $queued;
        });
    }

    /**
     * @param  array<int, string>|null  $channels  null = every channel (closes the posting)
     */
    public function unpublish(JobPosting $posting, ?array $channels = null, ?Employee $actor = null, string $reason = 'Unpublished'): void
    {
        DB::transaction(function () use ($posting, $channels, $actor, $reason): void {
            $distributions = $posting->distributions()
                ->when($channels !== null, fn ($q) => $q->whereIn('channel', $channels))
                ->whereIn('status', [DistributionStatus::Published, DistributionStatus::Pending, DistributionStatus::Paused])
                ->get();

            $distributions->each(fn (JobDistribution $d) => PublishJobDistributionJob::dispatch($d->id, 'unpublish')->afterCommit());

            if ($channels === null) {
                $posting->forceFill(['status' => JobPostingStatus::Closed])->save();
            }

            AuditLog::record($posting, 'job_unpublished', null, ['channels' => $distributions->pluck('channel')->all(), 'reason' => $reason, 'actor_employee_id' => $actor?->id]);
        });
    }

    public function pause(JobPosting $posting, ?Employee $actor = null): void
    {
        if ($posting->status !== JobPostingStatus::Published) {
            throw new DomainException('Only a published posting can be paused.');
        }

        DB::transaction(function () use ($posting, $actor): void {
            $posting->forceFill(['status' => JobPostingStatus::Paused])->save();
            $posting->distributions()->where('status', DistributionStatus::Published)->get()
                ->each(fn (JobDistribution $d) => PublishJobDistributionJob::dispatch($d->id, 'pause')->afterCommit());

            AuditLog::record($posting, 'job_paused', null, ['actor_employee_id' => $actor?->id]);
        });
    }

    /**
     * Re-publishes a paused/closed posting to the channels it was previously on.
     *
     * @return Collection<int, JobDistribution>
     */
    public function republish(JobPosting $posting, ?Employee $actor = null): Collection
    {
        $channels = $posting->distributions()->pluck('channel')->all();

        return $this->publish($posting, $channels !== [] ? $channels : ['career_site'], $actor);
    }

    /**
     * Applies a connector result to a distribution (called by PublishJobDistributionJob).
     */
    public function record(JobDistribution $distribution, string $operation, DistributionResult $result): void
    {
        $status = match (true) {
            ! $result->ok => DistributionStatus::Failed,
            $operation === 'unpublish' => DistributionStatus::Unpublished,
            $operation === 'pause' => DistributionStatus::Paused,
            default => DistributionStatus::Published,
        };

        $distribution->forceFill([
            'status' => $status,
            'external_id' => $result->externalId ?? $distribution->external_id,
            'external_url' => $result->externalUrl ?? $distribution->external_url,
            'last_error' => $result->error,
            'attempts' => $distribution->attempts + 1,
            'last_synced_at' => now(),
            'published_at' => $status === DistributionStatus::Published ? ($distribution->published_at ?? now()) : $distribution->published_at,
            'unpublished_at' => $status === DistributionStatus::Unpublished ? now() : $distribution->unpublished_at,
        ])->save();

        if (! $result->ok) {
            AuditLog::record($distribution->posting, 'job_distribution_failed', null, ['channel' => $distribution->channel, 'operation' => $operation, 'error' => $result->error]);
        }
    }

    /**
     * Unpublishes live postings whose requisition is no longer Open or whose closing date passed
     * (scheduled daily: jobs:sync-distributions). Returns how many postings were closed.
     */
    public function closeStalePostings(): int
    {
        $stale = JobPosting::query()
            ->whereIn('status', [JobPostingStatus::Published, JobPostingStatus::Paused])
            ->where(fn ($q) => $q->whereDate('closes_at', '<', today())
                ->orWhereHas('requisition', fn ($r) => $r->where('status', '!=', RequisitionStatus::Open)))
            ->get();

        // Phase 8.7 (D8.7-011): one posting that cannot close never stops the others.
        $closed = 0;

        foreach ($stale as $posting) {
            try {
                $this->unpublish($posting, reason: 'Requisition no longer open or closing date passed');
                $closed++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $closed;
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'job';
        $slug = $base;
        $n = 2;

        while (JobPosting::query()->where('public_slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }
}
