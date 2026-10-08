<?php

namespace App\Services\Distribution;

use App\Models\JobDistribution;
use App\Models\JobPosting;
use App\Services\Integrations\Contracts\Integration;

/**
 * One distribution channel for job postings (Phase 5): the internal career site, the XML feed, or
 * an external job board. JobDistributionService only ever talks to this contract; vendor API calls
 * live in the connector classes. A connector without real API access must report
 * isConfigured() = false and fail publish() — it must never claim a posting is live.
 */
interface JobBoardConnector extends Integration
{
    /**
     * Channel-specific problems with the posting (e.g. a required field the board needs).
     *
     * @return array<int, string>
     */
    public function validate(JobPosting $posting): array;

    public function publish(JobPosting $posting): DistributionResult;

    public function update(JobPosting $posting, JobDistribution $distribution): DistributionResult;

    public function unpublish(JobDistribution $distribution): DistributionResult;

    public function status(JobDistribution $distribution): DistributionResult;
}
