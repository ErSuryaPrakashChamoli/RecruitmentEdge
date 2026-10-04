<?php

namespace App\Console\Commands;

use App\Enums\BillingInterval;
use App\Services\Billing\Money;
use App\Services\Billing\PriceCatalogService;
use App\Services\Platform\Commercial\PlanAssignmentService;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * SaaS-4: platform staff publish what a plan costs (a new price replaces the current one; prices
 * are never edited). Production prices are an owner decision.
 */
#[Signature('billing:price
    {plan : Plan code (its latest published version)}
    {amount : Decimal amount, e.g. 4999.00}
    {--currency=INR}
    {--interval=month : month or year}')]
#[Description('Publish the price of a plan (platform)')]
class BillingPrice extends Command
{
    public function handle(PriceCatalogService $prices, PlanAssignmentService $plans): int
    {
        try {
            $interval = BillingInterval::tryFrom((string) $this->option('interval')) ?? throw new DomainException('The interval is month or year.');
            $price = $prices->publish($plans->latestVersion((string) $this->argument('plan')), Money::parse((string) $this->argument('amount'), (string) $this->option('currency')), $interval);
        } catch (DomainException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$price->planVersion->label()}: {$price->money()->format()} {$interval->label()} (price #{$price->id}).");

        return self::SUCCESS;
    }
}
