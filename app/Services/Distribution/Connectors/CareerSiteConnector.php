<?php

namespace App\Services\Distribution\Connectors;

use App\Models\JobDistribution;
use App\Models\JobPosting;
use App\Services\Communication\Data\IntegrationTestResult;
use App\Services\Distribution\DistributionResult;
use App\Services\Distribution\JobBoardConnector;

/**
 * The organisation's own career site served by this application (/careers). Publishing makes the
 * posting visible there; applications flow into the existing Candidate → Application pipeline via
 * CareerApplicationService. Operational without external credentials.
 */
class CareerSiteConnector implements JobBoardConnector
{
    public function key(): string
    {
        return 'career_site';
    }

    public function label(): string
    {
        return 'Career site (this application)';
    }

    public function category(): string
    {
        return 'job_board';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function testConnection(): IntegrationTestResult
    {
        return new IntegrationTestResult(true, 'Career site available at '.route('careers.index').'.');
    }

    public function validate(JobPosting $posting): array
    {
        return array_values(array_filter([
            blank($posting->title) ? 'A title is required.' : null,
            mb_strlen(strip_tags((string) $posting->description)) < 50 ? 'The description must be at least 50 characters for the career site.' : null,
        ]));
    }

    public function publish(JobPosting $posting): DistributionResult
    {
        return new DistributionResult(true, $posting->public_slug, route('careers.show', $posting->public_slug));
    }

    public function update(JobPosting $posting, JobDistribution $distribution): DistributionResult
    {
        return $this->publish($posting);
    }

    public function unpublish(JobDistribution $distribution): DistributionResult
    {
        return new DistributionResult(true, $distribution->external_id);
    }

    public function status(JobDistribution $distribution): DistributionResult
    {
        return new DistributionResult($distribution->posting?->isLive() ?? false, $distribution->external_id, $distribution->external_url);
    }
}
