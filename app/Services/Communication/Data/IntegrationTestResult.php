<?php

namespace App\Services\Communication\Data;

/**
 * Outcome of an explicit "test connection" against a real provider endpoint.
 */
final readonly class IntegrationTestResult
{
    public function __construct(public bool $ok, public string $message) {}
}
