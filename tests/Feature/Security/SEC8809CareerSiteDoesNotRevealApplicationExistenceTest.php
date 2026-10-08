<?php

use App\Models\CandidateApplication;
use App\Models\JobPosting;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/Sec88ContainmentHelpers.php';

/**
 * SEC-88-09: the public response is the same whatever the submitted contact details belong to —
 * it never shows an application code or says the person already applied.
 *
 * Livewire's "a component rendered this request" flag is static, so an earlier test in the same
 * process that rendered a Filament page over HTTP would otherwise make only the first page here
 * carry Livewire's injected assets. Resetting it keeps the byte-for-byte comparison independent
 * of test order.
 */
beforeEach(function (): void {
    Livewire::flushState();
    Storage::fake('local');
    $this->posting = sec88Posting();
    $this->victim = sec88ExistingCandidate();
    $this->existing = CandidateApplication::factory()->create(['candidate_id' => $this->victim->id, 'requisition_id' => $this->posting->requisition_id]);
});

function sec88NeutralBody(JobPosting $posting, array $overrides): array
{
    $response = sec88Apply($posting, $overrides);
    $page = test()->get($response->headers->get('Location'));
    $body = preg_replace('/(csrf-token" content="|name="_token" value=")[^"]+/', '$1', (string) $page->getContent());

    return ['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'session' => [session('careers_reference'), session('careers_existing')], 'body' => $body];
}

test('new, existing, already-applied, opted-out and repeated submissions all get the same response', function (): void {
    $responses = [
        'new applicant' => sec88NeutralBody($this->posting, []),
        'repeat of the new applicant' => sec88NeutralBody($this->posting, []),
        'existing candidate by email (already applied)' => sec88NeutralBody($this->posting, ['email' => 'real.person@example.com']),
        'existing candidate by mobile (opted out, STOP)' => sec88NeutralBody($this->posting, ['mobile' => '9811122233']),
        'existing candidate, name + email' => sec88NeutralBody($this->posting, ['full_name' => 'Real Person', 'email' => 'real.person@example.com']),
    ];

    $reference = reset($responses);

    foreach ($responses as $label => $response) {
        expect($response)->toBe($reference, "{$label} differs from a new applicant's response");
    }

    expect($reference['status'])->toBe(302)
        ->and($reference['session'])->toBe([null, null])
        ->and(str_contains($reference['body'], $this->existing->application_code))->toBeFalse('the existing application code is shown')
        ->and(str_contains($reference['body'], 'APP-'))->toBeFalse('an application code is shown')
        ->and(str_contains(strtolower($reference['body']), 'already'))->toBeFalse('the page says the person already applied')
        ->and(str_contains($reference['body'], 'Thank you for applying'))->toBeTrue();
});

test('an invalid submission gives the same validation errors whether or not the contact exists', function (): void {
    $known = sec88Apply($this->posting, ['email' => 'real.person@example.com', 'privacy_consent' => null]);
    $unknown = sec88Apply($this->posting, ['privacy_consent' => null]);

    foreach ([$known, $unknown] as $response) {
        $response->assertSessionHasErrors(['privacy_consent'])->assertSessionDoesntHaveErrors(['email', 'mobile', 'application']);
    }
});
