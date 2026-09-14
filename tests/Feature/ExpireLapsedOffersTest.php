<?php

use App\Enums\OfferStatus;
use App\Models\Offer;

test('offers:expire-lapsed expires released offers past their validity date', function (): void {
    $lapsed = Offer::factory()->create(['status' => OfferStatus::Released, 'offer_expiry' => now()->subDays(2)]);
    $stillValid = Offer::factory()->create(['status' => OfferStatus::Released, 'offer_expiry' => now()->addDay()]);

    $this->artisan('offers:expire-lapsed')
        ->expectsOutputToContain('Expired 1 offer(s)')
        ->assertSuccessful();

    expect($lapsed->refresh()->status)->toBe(OfferStatus::Expired)
        ->and($lapsed->statusHistory()->sole()->remarks)->toBe('Offer validity lapsed')
        ->and($stillValid->refresh()->status)->toBe(OfferStatus::Released);
});
