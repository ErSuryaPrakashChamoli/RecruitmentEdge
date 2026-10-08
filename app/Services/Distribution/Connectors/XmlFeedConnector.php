<?php

namespace App\Services\Distribution\Connectors;

use App\Models\JobDistribution;
use App\Models\JobPosting;
use App\Services\Communication\Data\IntegrationTestResult;
use App\Services\Distribution\DistributionResult;
use App\Services\Distribution\JobBoardConnector;

/**
 * A public XML job feed (/careers/feed.xml, Indeed-style schema) that aggregators and job boards
 * can be pointed at to crawl postings. "Published" means included in the feed — whether a given
 * board actually ingests the feed is configured on that board's side and is not tracked here.
 */
class XmlFeedConnector implements JobBoardConnector
{
    public function key(): string
    {
        return 'xml_feed';
    }

    public function label(): string
    {
        return 'XML job feed (for aggregators)';
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
        return new IntegrationTestResult(true, 'Feed available at '.route('careers.feed').' — register this URL with the job boards that accept XML feeds.');
    }

    public function validate(JobPosting $posting): array
    {
        return array_values(array_filter([
            $posting->requisition?->location === null ? 'Feeds need the requisition to have a location.' : null,
        ]));
    }

    public function publish(JobPosting $posting): DistributionResult
    {
        return new DistributionResult(true, $posting->public_slug, route('careers.feed'));
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
