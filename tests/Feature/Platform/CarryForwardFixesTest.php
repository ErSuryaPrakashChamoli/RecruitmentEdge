<?php

use App\Http\Controllers\PrivateFileController;
use App\Mail\CandidateMessageMail;
use App\Mail\CandidatePortalLink;
use App\Mail\CandidateStepUpCode;
use App\Mail\TenantInvitationMail;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\Role;
use App\Services\Communication\MessageContext;
use App\Services\Communication\TemplateRenderer;
use App\Services\Identity\TenantInvitationService;
use App\Services\OfferLetterRenderer;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\IdentityAccess\IdentityWorld;

/*
 * SaaS-5 carry-forwards: the invitation token never reaches the access log (S2-A3); a tenant's own
 * surfaces carry the tenant's name, the platform's surfaces the platform's (S1-08); employee photos
 * are private (S1-06).
 */
beforeEach(function (): void {
    $this->world = IdentityWorld::build($this->tenant);
    $this->tenant->forceFill(['branding' => ['display_name' => 'Acme Careers'], 'legal_name' => 'Acme Hiring Private Limited'])->save();
    TenantContext::current()->setTenant($this->tenant->fresh());
    config(['platform.brand.name' => 'RecruitmentEdge']);
});

test('an invitation link carries its token in the query string, which the access log never records; links sent before still work', function (): void {
    Mail::fake();
    app(TenantInvitationService::class)->invite(['email' => 'new@example.test', 'roles' => [Role::byKeyOrFail('recruiter')->id]], $this->world->adminA);
    $url = Mail::sent(TenantInvitationMail::class)->last()->url;
    $token = (string) str($url)->after('token=');
    $path = (string) parse_url($url, PHP_URL_PATH);

    expect($path)->toBe('/invitations/open')
        ->and(strlen($token))->toBe(64)
        ->and(file_get_contents(base_path('docker/apache/000-default.conf')))->toContain('%U')->not->toContain('%r')->not->toContain('%q');

    $this->get($url)->assertRedirect(route('invitations.show'));
    $this->get(route('invitations.open', ['token' => $token]))->assertRedirect(route('invitations.show'));
    $this->get('/invitations/open?token=short')->assertNotFound();
    $this->get('/invitations/open')->assertNotFound();
});

test('a tenant\'s own surfaces carry its name; the platform\'s carry the platform\'s', function (): void {
    $candidate = Candidate::factory()->create();
    $offer = Offer::factory()->create();

    expect((new CandidatePortalLink('Asha', 'https://x.test', isInvitation: true))->envelope()->subject)->toBe('Your Acme Careers candidate portal access')
        ->and((new CandidateStepUpCode('Asha', '123456', 10))->envelope()->subject)->toBe('Your Acme Careers verification code')
        ->and((new CandidateMessageMail('Hello', 'Body', 'ref-1'))->render())->toContain('Acme Careers')->not->toContain('RecruitmentEdge')
        ->and(app(TemplateRenderer::class)->render('Welcome to {{company.name}}', new MessageContext($candidate)))->toBe('Welcome to Acme Careers')
        ->and(app(OfferLetterRenderer::class)->mergeTagValues($offer)['company_name'])->toBe('Acme Hiring Private Limited')
        ->and((new TenantInvitationMail('Acme Careers', null, 'https://x.test', 'Monday'))->envelope()->subject)->toBe("You're invited to join Acme Careers on ".config('app.name'));

    $this->get(route('careers.index'))->assertOk()->assertSee('Find a role at Acme Careers')->assertDontSee('Find a role at '.config('app.name'));
    $this->get(route('careers.feed'))->assertOk()->assertSee('<publisher>Acme Careers</publisher>', false);
});

test('employee photos are private: a short-lived signed link for members of the tenant only', function (): void {
    $employee = Employee::factory()->create(['photo_path' => "tenants/{$this->tenant->id}/employee-photos/a.jpg"]);
    Storage::disk('local')->put($employee->photo_path, 'jpeg');
    $this->actingAs($this->world->personB);

    $url = $employee->photoUrl();

    expect($url)->toStartWith('/files/private?')->toContain('signature=');
    $this->get($url)->assertOk();

    $this->actingAs($this->world->adminB);
    $this->get($url)->assertForbidden();

    $outsider = TenantContext::current()->run($this->world->beta, fn () => PrivateFileController::temporaryUrl($employee->photo_path, now()->addMinutes(5)));
    $this->get($outsider)->assertNotFound();
});

test('photos not yet moved stay readable, and the move is idempotent, keeps every path and leaves no public copy', function (): void {
    $moved = Employee::factory()->create(['photo_path' => 'employee-photos/legacy.jpg']);
    $stillPublic = Employee::factory()->create(['photo_path' => "tenants/{$this->tenant->id}/employee-photos/b.jpg"]);
    Storage::disk('public')->put($moved->photo_path, 'legacy');
    Storage::disk('public')->put($stillPublic->photo_path, 'new');

    expect(Employee::photoDisk($moved->photo_path))->toBe('public')
        ->and($moved->photoUrl())->toBe(Storage::disk('public')->url($moved->photo_path));

    $this->artisan('tenants:run files:privatize-employee-photos --tenant=acme --with=dry-run')->assertSuccessful();
    expect(Storage::disk('public')->exists($moved->photo_path))->toBeTrue();

    $this->artisan('tenants:run files:privatize-employee-photos --tenant=acme')->assertSuccessful();
    $this->artisan('tenants:run files:privatize-employee-photos --tenant=acme')->assertSuccessful();

    foreach ([$moved, $stillPublic] as $employee) {
        expect(Storage::disk('local')->exists($employee->photo_path))->toBeTrue()
            ->and(Storage::disk('public')->exists($employee->photo_path))->toBeFalse()
            ->and($employee->fresh()->photo_path)->toBe($employee->photo_path)
            ->and(Employee::photoDisk($employee->photo_path))->toBe('local');
    }
});

test('an interrupted photo move is repaired: a partial private copy is replaced by the intact public one', function (): void {
    $employee = Employee::factory()->create(['photo_path' => "tenants/{$this->tenant->id}/employee-photos/c.jpg"]);
    Storage::disk('public')->put($employee->photo_path, 'complete image');
    Storage::disk('local')->put($employee->photo_path, 'compl');

    $this->artisan('tenants:run files:privatize-employee-photos --tenant=acme')->assertSuccessful();

    expect(Storage::disk('local')->get($employee->photo_path))->toBe('complete image')
        ->and(Storage::disk('public')->exists($employee->photo_path))->toBeFalse()
        ->and(Storage::disk('local')->exists($employee->photo_path.'.part'))->toBeFalse();
});
