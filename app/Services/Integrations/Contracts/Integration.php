<?php

namespace App\Services\Integrations\Contracts;

use App\Services\Communication\Data\IntegrationTestResult;

/**
 * Anything that talks to an external system (communication, calendar, video, job boards). Lets
 * IntegrationRegistry report every integration's state honestly:
 *
 * - implemented: the adapter class exists (it is registered);
 * - configured: isConfigured() — credentials/config present;
 * - operational: the last explicit testConnection() succeeded (integration_statuses).
 */
interface Integration
{
    public function key(): string;

    public function label(): string;

    /**
     * communication | calendar | video | job_board
     */
    public function category(): string;

    public function isConfigured(): bool;

    public function testConnection(): IntegrationTestResult;
}
