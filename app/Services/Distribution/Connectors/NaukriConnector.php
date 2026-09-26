<?php

namespace App\Services\Distribution\Connectors;

/**
 * Naukri — extension point only until API access is configured (see UnavailableJobBoardConnector).
 */
class NaukriConnector extends UnavailableJobBoardConnector
{
    public function key(): string
    {
        return 'naukri';
    }

    public function label(): string
    {
        return 'Naukri';
    }
}
