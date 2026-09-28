<?php

use App\Enums\CommunicationChannel;
use App\Enums\PreferenceStatus;
use App\Services\Communication\CommunicationPreferenceService;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Sec88ContainmentHelpers.php';

/**
 * SEC-88-01: an email opt-out and a WhatsApp STOP stay in force whatever an anonymous submission
 * ticks, so the Phase 8.7 send-time check keeps blocking both channels.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->posting = sec88Posting();
    $this->victim = sec88ExistingCandidate();
});

test('an email opt-out and a WhatsApp STOP survive every matching submission, twice over', function (array $variant): void {
    sec88Apply($this->posting, [...$variant, 'consent_email' => '1', 'consent_whatsapp' => '1']);
    sec88Apply($this->posting, [...$variant, 'consent_email' => '1', 'consent_whatsapp' => '1']);

    $preferences = app(CommunicationPreferenceService::class);
    $victim = $this->victim->fresh();

    expect($preferences->statusFor($victim, CommunicationChannel::Email))->toBe(PreferenceStatus::OptedOut)
        ->and($preferences->statusFor($victim, CommunicationChannel::WhatsApp))->toBe(PreferenceStatus::OptedOut)
        ->and($preferences->blockedReason($victim, CommunicationChannel::Email))->toContain('opted out')
        ->and($preferences->blockedReason($victim, CommunicationChannel::WhatsApp))->toContain('opted out')
        ->and($victim->communicationPreferences()->where('source', 'career_site_application')->exists())->toBeFalse();
})->with(sec88MatchingVariants());
