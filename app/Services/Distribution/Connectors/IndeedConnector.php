<?php

namespace App\Services\Distribution\Connectors;

/**
 * Indeed — extension point only until API access is configured (see UnavailableJobBoardConnector).
 */
class IndeedConnector extends UnavailableJobBoardConnector
{
    public function key(): string
    {
        return 'indeed';
    }

    public function label(): string
    {
        return 'Indeed';
    }
}
