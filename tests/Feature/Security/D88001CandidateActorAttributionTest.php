<?php

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidatePortalAccount;
use App\Models\Interview;
use App\Models\InterviewSlotBooking;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/D88001Helpers.php';

/**
 * D8.8-001 identity boundary: candidate self-service is recorded as the candidate — resolved on the
 * server — never as a staff user signed in to the same browser, and never from anything the
 * browser supplies.
 */
beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

function d88BookingAuditRows(): Collection
{
    return AuditLog::query()->whereIn('auditable_type', [Interview::class, InterviewSlotBooking::class])->get();
}

test('a booking through a signed link while a staff user is signed in is recorded as the candidate', function (): void {
    $account = d88Account();
    $staff = d88Staff();
    $invitation = d88Invitation($account);
    AuditLog::query()->delete();

    $this->actingAs($staff, 'web');
    $this->post(URL::temporarySignedRoute('portal.schedule.book', now()->addMinutes(30), ['invitation' => $invitation->public_id]), ['slot' => d88Slot()->public_id])->assertRedirect();

    $rows = d88BookingAuditRows();

    expect($rows)->not->toBeEmpty()
        ->and($rows->pluck('actor_kind')->unique()->values()->all())->toBe(['candidate'])
        ->and($rows->pluck('user_id')->filter()->all())->toBe([])
        ->and($rows->pluck('actor_type')->unique()->values()->all())->toBe([(new CandidatePortalAccount)->getMorphClass()])
        ->and($rows->pluck('actor_id')->unique()->values()->all())->toBe([$account->id]);
});

test('reschedule and cancel through signed links are recorded as the candidate too', function (): void {
    $account = d88Account();
    $invitation = d88Invitation($account);
    $this->post(URL::temporarySignedRoute('portal.schedule.book', now()->addMinutes(30), ['invitation' => $invitation->public_id]), ['slot' => d88Slot()->public_id]);
    $booking = InterviewSlotBooking::query()->sole();
    AuditLog::query()->delete();

    $this->actingAs(d88Staff(), 'web');
    $this->post(URL::temporarySignedRoute('portal.bookings.reschedule', now()->addMinutes(30), ['booking' => $booking->public_id]), ['slot' => d88Slot()->public_id])->assertRedirect();
    $moved = InterviewSlotBooking::query()->where('id', '!=', $booking->id)->sole();
    $this->post(URL::temporarySignedRoute('portal.bookings.cancel', now()->addMinutes(30), ['booking' => $moved->public_id]), ['reason' => 'Cannot make it'])->assertRedirect();

    $rows = d88BookingAuditRows();

    expect($rows->count())->toBeGreaterThan(1)
        ->and($rows->pluck('actor_kind')->unique()->values()->all())->toBe(['candidate'])
        ->and($rows->pluck('user_id')->filter()->all())->toBe([]);
});

test('a candidate without a portal account is recorded as the candidate record', function (): void {
    $account = d88Account();
    $candidate = $account->candidate;
    $invitation = d88Invitation($account);
    $account->delete();
    AuditLog::query()->delete();

    $this->post(URL::temporarySignedRoute('portal.schedule.book', now()->addMinutes(30), ['invitation' => $invitation->public_id]), ['slot' => d88Slot()->public_id])->assertRedirect();

    expect(d88BookingAuditRows()->pluck('actor_type')->unique()->values()->all())->toBe([(new Candidate)->getMorphClass()])
        ->and(d88BookingAuditRows()->pluck('actor_id')->unique()->values()->all())->toBe([$candidate->id]);
});

test('actor fields sent by the browser are ignored', function (): void {
    $account = d88Account();
    $other = d88Account();
    $invitation = d88Invitation($account);
    AuditLog::query()->delete();

    $this->withHeader('X-Actor-Id', (string) $other->id)
        ->post(URL::temporarySignedRoute('portal.schedule.book', now()->addMinutes(30), ['invitation' => $invitation->public_id]), ['slot' => d88Slot()->public_id, 'actor_id' => $other->id, 'candidate_id' => $other->candidate_id, 'user_id' => 1]);

    expect(d88BookingAuditRows()->pluck('actor_id')->unique()->values()->all())->toBe([$account->id]);
});

test('a signed-in candidate\'s portal changes are recorded as that candidate', function (): void {
    $account = d88Account();
    $this->actingAs($account, 'candidate');
    AuditLog::query()->delete();

    $this->put(route('portal.profile.update'), ['current_city' => 'Pune', 'channels' => ['email' => '1']])->assertRedirect();

    $rows = AuditLog::query()->get();

    expect($rows)->not->toBeEmpty()
        ->and($rows->pluck('actor_kind')->unique()->values()->all())->toBe(['candidate'])
        ->and($rows->pluck('user_id')->filter()->all())->toBe([]);
});

test('a staff action on a staff page stays recorded as the staff user', function (): void {
    $staff = d88Staff();
    $this->actingAs($staff, 'web');

    AuditLog::record($staff, 'probe', null, null);

    expect(AuditLog::query()->where('action', 'probe')->sole()->only(['actor_kind', 'user_id']))->toBe(['actor_kind' => 'user', 'user_id' => $staff->id]);
});

test('one candidate cannot reach another candidate\'s scheduling or data through the portal', function (): void {
    $mine = d88Account();
    $theirs = d88Account();
    $theirInvitation = d88Invitation($theirs);
    $theirApplication = $theirInvitation->candidateApplication;

    $this->actingAs($mine, 'candidate');

    $this->get(route('portal.schedule.show', $theirInvitation->public_id))->assertNotFound();
    $this->get(route('portal.applications.show', $theirApplication->application_code))->assertNotFound();
});
