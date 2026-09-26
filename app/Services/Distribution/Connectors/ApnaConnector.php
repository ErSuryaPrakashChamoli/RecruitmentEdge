<?php

namespace App\Services\Distribution\Connectors;

/**
 * Apna — extension point only until API access is configured (see UnavailableJobBoardConnector).
 */
class ApnaConnector extends UnavailableJobBoardConnector
{
    public function key(): string
    {
        return 'apna';
    }

    public function label(): string
    {
        return 'Apna';
    }
}
