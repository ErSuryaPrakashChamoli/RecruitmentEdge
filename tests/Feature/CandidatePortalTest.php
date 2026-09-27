<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\DocumentType;
use App\Enums\InterviewStatus;
use App\Enums\SchedulingChannel;
use App\Enums\TimelineEventType;
use App\Filament\Resources\CandidateApplications\Pages\ViewCandidateApplication;
use App\Filament\Resources\Candidates\Pages\ViewCandidate;
use App\Mail\CandidatePortalLink;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateDocument;
use App\Models\CandidatePortalAccount;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewAvailabilitySlot;
use App\Models\InterviewSchedulingInvitation;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\CandidatePortalService;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\InterviewSchedulingService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

function portalAccount(array $candidate = []): CandidatePortalAccount
{
    return CandidatePortalAccount::factory()->create(['candidate_id' => Candidate::factory()->create($candidate)->id]);
}

function portalApplication(CandidatePortalAccount $account, array $attributes = []): CandidateApplication
{
    return CandidateApplication::factory()->create(['candidate_id' => $account->candidate_id, ...$attributes]);
}

describe('signing in', function (): void {
    test('guests are sent to the portal sign-in page', function (): void {
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
    });

    test('a candidate signs in with their email and password', function (): void {
        $account = portalAccount();

        $this->post(route('portal.login.store'), ['email' => strtoupper($account->email), 'password' => 'Secret#12345'])
            ->assertRedirect(route('portal.dashboard'));

        $this->assertAuthenticatedAs($account, 'candidate');
        expect($account->fresh()->last_login_at)->not->toBeNull();
    });

    test('sign-in fails for a wrong password, a revoked account or one without a password yet', function (callable $makeAccount, string $password): void {
        $account = $makeAccount();

        $this->post(route('portal.login.store'), ['email' => $account->email, 'password' => $password])
            ->assertSessionHasErrors(['email' => 'These details do not match our records.']);

        $this->assertGuest('candidate');
    })->with([
        'wrong password' => [fn () => portalAccount(), 'nope'],
        'revoked account' => [fn () => tap(portalAccount(), fn (CandidatePortalAccount $account) => $account->forceFill(['is_active' => false])->save()), 'Secret#12345'],
        'no password set' => [fn () => CandidatePortalAccount::factory()->withoutPassword()->create(), 'Secret#12345'],
    ]);

    test('repeated failed sign-ins are locked out', function (): void {
        $account = portalAccount();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('portal.login.store'), ['email' => $account->email, 'password' => 'wrong']);
        }

        $this->post(route('portal.login.store'), ['email' => $account->email, 'password' => 'Secret#12345'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('candidate');
    });

    test('a candidate session does not grant admin panel access', function (): void {
        actingAs(portalAccount(), 'candidate');

        $this->get('/admin')->assertRedirect();
    });

    test('a signed-in staff user is not a portal candidate', function (): void {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->create();
        $staff->assignRole('chro');
        actingAs($staff);

        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
    });

    test('a candidate whose access is revoked mid-session is signed out', function (): void {
        $account = portalAccount();
        actingAs($account, 'candidate');
        $account->forceFill(['is_active' => false])->save();

        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.login'));
        $this->assertGuest('candidate');
    });
});

describe('password links', function (): void {
    test('a signed link sets the password once and signs the candidate in', function (): void {
        $account = CandidatePortalAccount::factory()->withoutPassword()->create();
        $link = app(CandidatePortalService::class)->passwordLink($account);

        $this->get($link)->assertOk()->assertSee($account->email);
        $this->post($link, ['password' => 'NewPassw0rdX', 'password_confirmation' => 'NewPassw0rdX'])->assertRedirect(route('portal.dashboard'));

        $this->assertAuthenticatedAs($account->fresh(), 'candidate');
        auth('candidate')->logout();
        $this->get($link)->assertForbidden();
    });

    test('an unsigned or tampered link is refused', function (): void {
        $account = CandidatePortalAccount::factory()->withoutPassword()->create();

        $this->get(route('portal.password.edit', ['account' => $account->public_id, 'k' => 'x']))->assertForbidden();
        $this->get(app(CandidatePortalService::class)->passwordLink($account).'x')->assertForbidden();
    });

    test('weak passwords are rejected', function (): void {
        $link = app(CandidatePortalService::class)->passwordLink(CandidatePortalAccount::factory()->withoutPassword()->create());

        $this->post($link, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    });

    test('forgot password answers the same whether or not the email has an account', function (): void {
        Mail::fake();
        $account = portalAccount();

        $this->post(route('portal.password.email'), ['email' => 'nobody@example.com'])->assertSessionHas('status');
        $this->post(route('portal.password.email'), ['email' => $account->email])->assertSessionHas('status');

        Mail::assertQueued(CandidatePortalLink::class, 1);
    });
});

describe('applications', function (): void {
    test('the dashboard lists only the candidate\'s own applications', function (): void {
        $account = portalAccount();
        $mine = portalApplication($account);
        $theirs = CandidateApplication::factory()->create();
        actingAs($account, 'candidate');

        $this->get(route('portal.dashboard'))->assertOk()->assertSee($mine->application_code)->assertDontSee($theirs->application_code);
    });

    test('another candidate\'s application is not found', function (): void {
        actingAs(portalAccount(), 'candidate');

        $this->get(route('portal.applications.show', CandidateApplication::factory()->create()->application_code))->assertNotFound();
    });

    test('internal remarks and rejection reasons are never shown to the candidate', function (): void {
        $account = portalAccount();
        $application = portalApplication($account, ['remarks' => 'INTERNAL: salary too high']);
        app(StageTransitionService::class)->reject($application, RecruitmentRejectionReason::factory()->create(['name' => 'Culture mismatch']), remarks: 'Recruiter-only note');
        actingAs($account, 'candidate');

        $this->get(route('portal.applications.show', $application->application_code))
            ->assertOk()
            ->assertSee('Not progressing')
            ->assertDontSee('INTERNAL: salary too high')
            ->assertDontSee('Culture mismatch')
            ->assertDontSee('Recruiter-only note');
    });

    test('names are escaped in portal pages', function (): void {
        $account = portalAccount(['full_name' => '<script>alert(1)</script>']);
        actingAs($account, 'candidate');

        $this->get(route('portal.dashboard'))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    });

    test('a candidate confirms their own interview', function (): void {
        $account = portalAccount();
        $application = portalApplication($account);
        $interview = Interview::factory()->create(['candidate_application_id' => $application->id, 'round_number' => 1, 'status' => InterviewStatus::Scheduled]);
        actingAs($account, 'candidate');

        $this->post(route('portal.interviews.confirm', [$application->application_code, 1]))->assertSessionHas('status');

        expect($interview->fresh()->status)->toBe(InterviewStatus::Confirmed)
            ->and($account->candidate->timelineEvents()->where('event_type', TimelineEventType::InterviewConfirmed)->exists())->toBeTrue();
    });

    test('a candidate cannot confirm another candidate\'s interview', function (): void {
        $other = CandidateApplication::factory()->create();
        Interview::factory()->create(['candidate_application_id' => $other->id, 'round_number' => 1, 'status' => InterviewStatus::Scheduled]);
        actingAs(portalAccount(), 'candidate');

        $this->post(route('portal.interviews.confirm', [$other->application_code, 1]))->assertNotFound();
    });

    test('a reschedule request is recorded and the recruiter is alerted', function (): void {
        $this->seed(RolePermissionSeeder::class);
        $recruiter = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $account = portalAccount();
        $application = portalApplication($account, ['recruiter_id' => $recruiter->employee_id]);
        Interview::factory()->create(['candidate_application_id' => $application->id, 'round_number' => 1, 'status' => InterviewStatus::Scheduled]);
        actingAs($account, 'candidate');

        $this->post(route('portal.interviews.reschedule-request', [$application->application_code, 1]), ['reason' => 'I have an exam that day'])->assertSessionHas('status');

        expect($account->candidate->timelineEvents()->where('event_type', TimelineEventType::RescheduleRequested)->sole()->description)->toBe('I have an exam that day')
            ->and($recruiter->notifications()->count())->toBe(1);
    });
});

describe('profile and documents', function (): void {
    test('a candidate updates permitted fields while protected fields are ignored', function (): void {
        $account = portalAccount(['full_name' => 'Original Name', 'expected_salary' => 500000]);
        actingAs($account, 'candidate');

        $this->put(route('portal.profile.update'), [
            'current_city' => 'Pune',
            'notice_period_days' => 30,
            'full_name' => 'Hacked',
            'expected_salary' => 99999999,
            'email' => 'evil@example.com',
            'communication_preferences' => ['email' => '1', 'sms' => '0', 'whatsapp' => '1', 'phone' => '1'],
        ])->assertSessionHas('status');

        $candidate = $account->candidate->fresh();

        expect($candidate->current_city)->toBe('Pune')
            ->and($candidate->notice_period_days)->toBe(30)
            ->and($candidate->full_name)->toBe('Original Name')
            ->and((float) $candidate->expected_salary)->toBe(500000.0)
            ->and(collect(app(CommunicationPreferenceService::class)->allFor($candidate))->map->value->all())
            ->toBe(['email' => 'allowed', 'whatsapp' => 'allowed', 'sms' => 'opted_out', 'phone' => 'allowed'])
            ->and($candidate->timelineEvents()->where('event_type', TimelineEventType::ProfileUpdated)->exists())->toBeTrue();
    });

    test('a valid document is stored privately and recorded for recruiter review', function (): void {
        Storage::fake('local');
        $account = portalAccount();
        actingAs($account, 'candidate');

        $this->post(route('portal.documents.store'), [
            'document_type' => DocumentType::Resume->value,
            'file' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf'),
        ])->assertSessionHas('status');

        $document = CandidateDocument::query()->sole();

        expect($document->candidate_id)->toBe($account->candidate_id)
            ->and($document->file_path)->toStartWith("candidate-documents/{$account->candidate_id}/")
            ->and(AuditLog::query()->where('action', 'portal_uploaded')->sole())
            ->user_id->toBeNull()
            ->actor_type->toBe($account->getMorphClass())
            ->actor_id->toBe($account->id);
        Storage::disk('local')->assertExists($document->file_path);
    });

    test('unsafe or oversized uploads are rejected', function (UploadedFile $file, string $type): void {
        Storage::fake('local');
        actingAs(portalAccount(), 'candidate');

        $this->post(route('portal.documents.store'), ['document_type' => $type, 'file' => $file])->assertSessionHasErrors();

        expect(CandidateDocument::query()->count())->toBe(0);
    })->with([
        'executable' => [UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload'), 'resume'],
        'too large' => [UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'), 'resume'],
        'document type not allowed from the portal' => [UploadedFile::fake()->create('bank.pdf', 10, 'application/pdf'), 'bank_details'],
    ]);
});

describe('self-scheduling', function (): void {
    function portalInvitation(?CandidatePortalAccount $account = null): InterviewSchedulingInvitation
    {
        $application = $account !== null ? portalApplication($account, ['current_stage' => CandidateStage::Shortlisted]) : CandidateApplication::factory()->create();

        return app(InterviewSchedulingService::class)->invite($application);
    }

    test('a signed link shows open slots without signing in', function (): void {
        $invitation = portalInvitation();
        $slot = InterviewAvailabilitySlot::factory()->create();

        $this->get(app(InterviewSchedulingService::class)->signedLinkFor($invitation))->assertOk()->assertSee($slot->public_id);
    });

    test('an unsigned link is not found for someone who does not own the invitation', function (): void {
        $invitation = portalInvitation();
        actingAs(portalAccount(), 'candidate');

        $this->get(route('portal.schedule.show', $invitation->public_id))->assertNotFound();
    });

    test('the owning portal candidate can open their invitation without a signature', function (): void {
        $account = portalAccount();
        $invitation = portalInvitation($account);
        actingAs($account, 'candidate');

        $this->get(route('portal.schedule.show', $invitation->public_id))->assertOk();
    });

    test('booking through the signed link creates the interview, and a double booking is refused', function (): void {
        $slot = InterviewAvailabilitySlot::factory()->create();
        $first = portalInvitation();
        $second = portalInvitation();

        $this->post(URL::temporarySignedRoute('portal.schedule.book', now()->addMinutes(30), ['invitation' => $first->public_id]), ['slot' => $slot->public_id])
            ->assertRedirect();
        $this->post(URL::temporarySignedRoute('portal.schedule.book', now()->addMinutes(30), ['invitation' => $second->public_id]), ['slot' => $slot->public_id])
            ->assertSessionHasErrors(['slot' => 'This slot has just been taken. Please choose another.']);

        expect($first->candidateApplication->interviews()->sole()->status)->toBe(InterviewStatus::Scheduled)
            ->and($second->candidateApplication->interviews()->count())->toBe(0);
    });

    test('a candidate reschedules their booking to another slot', function (): void {
        $account = portalAccount();
        $invitation = portalInvitation($account);
        $old = InterviewAvailabilitySlot::factory()->create();
        $new = InterviewAvailabilitySlot::factory()->startingAt(now()->addDays(5)->setTime(11, 0))->create();
        $booking = app(InterviewSchedulingService::class)->book($invitation, $old, SchedulingChannel::CandidatePortal, $account);
        actingAs($account, 'candidate');

        $this->get(route('portal.bookings.show', $booking->public_id))->assertOk()->assertSee($new->public_id);
        $this->post(route('portal.bookings.reschedule', $booking->public_id), ['slot' => $new->public_id, 'reason' => 'Travel'])->assertRedirect();

        expect($booking->interview->fresh()->scheduled_at->equalTo($new->starts_at))->toBeTrue()
            ->and($booking->interview->fresh()->status)->toBe(InterviewStatus::Rescheduled);
    });
});

describe('recruiter controls', function (): void {
    beforeEach(function (): void {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
        $user->assignRole('chro');
        actingAs($user);
    });

    test('a recruiter invites a candidate to the portal from the Candidate 360 view', function (): void {
        Mail::fake();
        $candidate = Candidate::factory()->create(['email' => 'priya@example.com']);

        Livewire::test(ViewCandidate::class, ['record' => $candidate->id])
            ->callAction('invitePortal', ['email' => 'priya@example.com'])
            ->assertNotified('Portal invitation sent');

        expect($candidate->portalAccount->email)->toBe('priya@example.com')
            ->and($candidate->portalAccount->password)->toBeNull();
        Mail::assertQueued(CandidatePortalLink::class, fn (CandidatePortalLink $mail) => $mail->hasTo('priya@example.com'));
    });

    test('a recruiter invites an application to self-schedule', function (): void {
        $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Active]);

        Livewire::test(ViewCandidateApplication::class, ['record' => $application->id])
            ->callAction('inviteToSelfSchedule', [])
            ->assertNotified('Self-scheduling invitation created');

        expect(InterviewSchedulingInvitation::query()->where('candidate_application_id', $application->id)->exists())->toBeTrue();
    });
});
