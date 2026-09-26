<?php

namespace App\Services\Distribution;

use App\Services\Distribution\Connectors\ApnaConnector;
use App\Services\Distribution\Connectors\CareerSiteConnector;
use App\Services\Distribution\Connectors\IndeedConnector;
use App\Services\Distribution\Connectors\LinkedInConnector;
use App\Services\Distribution\Connectors\NaukriConnector;
use App\Services\Distribution\Connectors\WorkIndiaConnector;
use App\Services\Distribution\Connectors\XmlFeedConnector;

/**
 * Every distribution channel the platform implements. Add a board by writing a JobBoardConnector
 * and listing it here — nothing else branches on board names.
 */
class JobBoardRegistry
{
    /**
     * @var array<string, class-string<JobBoardConnector>>
     */
    public const array CONNECTORS = [
        'career_site' => CareerSiteConnector::class,
        'xml_feed' => XmlFeedConnector::class,
        'linkedin' => LinkedInConnector::class,
        'naukri' => NaukriConnector::class,
        'indeed' => IndeedConnector::class,
        'apna' => ApnaConnector::class,
        'workindia' => WorkIndiaConnector::class,
    ];

    public function find(string $key): ?JobBoardConnector
    {
        return isset(self::CONNECTORS[$key]) ? app(self::CONNECTORS[$key]) : null;
    }

    /**
     * @return array<string, string> key => label, marking channels that are not configured
     */
    public function options(): array
    {
        return collect(self::CONNECTORS)->keys()->mapWithKeys(function (string $key): array {
            $connector = $this->find($key);

            return [$key => $connector->label().($connector->isConfigured() ? '' : ' (not configured)')];
        })->all();
    }
}
