<?php

namespace App\Console\Commands;

use App\Services\OfferService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('offers:expire-lapsed')]
#[Description('Move Released offers whose validity date has passed to Expired')]
class ExpireLapsedOffers extends Command
{
    public function handle(OfferService $offers): int
    {
        $count = $offers->expireLapsedOffers();

        $this->info("Expired {$count} offer(s) past their validity date.");

        return self::SUCCESS;
    }
}
