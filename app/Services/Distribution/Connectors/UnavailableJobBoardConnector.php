<?php

namespace App\Services\Distribution\Connectors;

use App\Models\JobDistribution;
use App\Models\JobPosting;
use App\Services\Communication\Data\IntegrationTestResult;
use App\Services\Distribution\DistributionResult;
use App\Services\Distribution\JobBoardConnector;

/**
 * Extension point for a job board whose posting API needs a commercial partnership/credentials
 * the organisation has not configured (LinkedIn, Naukri, Indeed, Apna, WorkIndia). It never
 * pretends: every operation fails with "not configured". To go live, replace the subclass body
 * with real API calls behind the same JobBoardConnector contract and add its credentials to
 * config/services.php.
 */
abstract class UnavailableJobBoardConnector implements JobBoardConnector
{
    public function category(): string
    {
        return 'job_board';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function testConnection(): IntegrationTestResult
    {
        return new IntegrationTestResult(false, $this->label().' API access is not configured (extension point only).');
    }

    public function validate(JobPosting $posting): array
    {
        return [];
    }

    public function publish(JobPosting $posting): DistributionResult
    {
        return DistributionResult::failed($this->label().' is not configured — no API access. The posting was not published there.');
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
        return DistributionResult::failed($this->label().' is not configured.');
    }
}
