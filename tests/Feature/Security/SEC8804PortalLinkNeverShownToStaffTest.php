<?php

use App\Filament\Resources\CandidatePortalAccounts\Pages\ListCandidatePortalAccounts;
use App\Filament\Resources\Candidates\Pages\ViewCandidate;
use App\Mail\CandidatePortalLink;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * SEC-88-04 (D8.8-037, owner decision 2026-10-01, A): staff never see a candidate's set-password
 * link. It is emailed to the candidate only — since D8.8-001 a password set through it is recorded
 * as the candidate, so nobody else may ever hold it.
 */
beforeEach(function (): void {
    Mail::fake();
    $this->seed(RolePermissionSeeder::class);
    $this->staff = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $this->actingAs($this->staff, 'web');
});

/**
 * Collects (and consumes, as the panel would) the notifications shown to the staff user so far:
 * title and body text.
 */
function sec8804ShownToStaff(): string
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications
        ->map(fn (Notification $notification): string => $notification->getTitle().' '.$notification->getBody())
        ->implode("\n");
}

test('inviting and re-inviting from the candidate page never shows the link; it is emailed to the candidate', function (): void {
    $candidate = Candidate::factory()->create(['email' => 'asha@example.com']);

    Livewire::test(ViewCandidate::class, ['record' => $candidate->id])
        ->callAction('invitePortal', ['email' => 'asha@example.com']);
    $shown = sec8804ShownToStaff();

    $candidate->portalAccount->forceFill(['password' => 'Secret#12345'])->save();

    Livewire::test(ViewCandidate::class, ['record' => $candidate->id])
        ->callAction('invitePortal', ['email' => 'asha@example.com']);
    $shown .= "\n".sec8804ShownToStaff();

    expect(substr_count($shown, 'Portal invitation sent'))->toBe(2)
        ->and(str_contains($shown, 'password/set'))->toBeFalse('a set-password link was shown to staff')
        ->and(str_contains($shown, 'signature='))->toBeFalse('a signed link was shown to staff')
        ->and(str_contains($shown, 'http'))->toBeFalse('a URL was shown to staff');
    Mail::assertQueued(CandidatePortalLink::class, 2);
    Mail::assertQueued(CandidatePortalLink::class, fn (CandidatePortalLink $mail): bool => $mail->hasTo('asha@example.com'));
});

test('resending from the portal accounts list never shows the link either', function (): void {
    $candidate = Candidate::factory()->create(['email' => 'ravi@example.com']);
    Livewire::test(ViewCandidate::class, ['record' => $candidate->id])->callAction('invitePortal', ['email' => 'ravi@example.com']);
    sec8804ShownToStaff();

    Livewire::test(ListCandidatePortalAccounts::class)
        ->callAction(TestAction::make('resend')->table($candidate->portalAccount));
    $shown = sec8804ShownToStaff();

    expect(str_contains($shown, 'Portal link sent'))->toBeTrue()
        ->and(str_contains($shown, 'http'))->toBeFalse('a URL was shown to staff');
    Mail::assertQueued(CandidatePortalLink::class, fn (CandidatePortalLink $mail): bool => $mail->hasTo('ravi@example.com'));
});
