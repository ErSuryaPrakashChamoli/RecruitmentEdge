<?php

namespace App\Services\Distribution\Connectors;

/**
 * LinkedIn Jobs — extension point only until API access is configured (see UnavailableJobBoardConnector).
 */
class LinkedInConnector extends UnavailableJobBoardConnector
{
    public function key(): string
    {
        return 'linkedin';
    }

    public function label(): string
    {
        return 'LinkedIn Jobs';
    }
}
