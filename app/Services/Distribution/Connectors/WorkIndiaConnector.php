<?php

namespace App\Services\Distribution\Connectors;

/**
 * WorkIndia — extension point only until API access is configured (see UnavailableJobBoardConnector).
 */
class WorkIndiaConnector extends UnavailableJobBoardConnector
{
    public function key(): string
    {
        return 'workindia';
    }

    public function label(): string
    {
        return 'WorkIndia';
    }
}
