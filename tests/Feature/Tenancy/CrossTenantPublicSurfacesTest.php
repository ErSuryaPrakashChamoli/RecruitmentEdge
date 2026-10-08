<?php

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\PreferenceStatus;
use App\Enums\TenantStatus;
use App\Http\Controllers\PrivateFileController;
use App\Models\Candidate;
use App\Models\CandidateCommunication;
use App\Models\CandidateDocument;
use App\Models\CommunicationWebhookEvent;
use App\Models\Export;
use App\Models\Tenant;
use App\Services\Communication\CommunicationPreferenceService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Tenancy\TenantWorld;

use function Pest\Laravel\actingAs;

/*
 * SaaS-1: the surfaces outside the panel — careers site, candidate portal, private file and export
 * downloads, provider webhooks, calendar OAuth — each resolve their tenant from something the
 * platform controls (the tenant slug in the path, a signed record, the provider's own message id)
 * and never cross into another tenant.
 */
beforeEach(function (): void {
    $this->alpha = TenantWorld::build(Tenant::factory()->create(['slug' => 'alpha', 'name' => 'Alpha Hiring']), 'ALPHA');
    $this->bravo = TenantWorld::build(Tenant::factory()->create(['slug' => 'bravo', 'name' => 'Bravo Hiring']), 'BRAVO');
    $this->actInTenant($this->alpha->tenant);
});

test('each careers site lists and shows only its own postings, even under the same slug', function (): void {
    $this->get('/careers/alpha')->assertOk()->assertSee('ALPHA Field Sales Executive')->assertDontSee('BRAVO');
    $this->get('/careers/alpha/field-sales-executive')->assertOk()->assertSee('ALPHA Field Sales Executive')->assertDontSee('BRAVO');
    $this->get('/careers/bravo/field-sales-executive')->assertOk()->assertSee('BRAVO Field Sales Executive')->assertDontSee('ALPHA');
    $this->get('/careers/alpha/feed.xml')->assertOk()->assertDontSee('BRAVO');
    $this->get('/careers/nowhere')->assertNotFound();

    $this->bravo->tenant->update(['status' => TenantStatus::Suspended]);
    $this->get('/careers/bravo')->assertNotFound();
});

test('an application on one tenant\'s careers site is created in that tenant only', function (): void {
    $count = fn (Tenant $tenant): int => TenantContext::current()->run($tenant, fn () => Candidate::query()->count());
    [$alphaBefore, $bravoBefore] = [$count($this->alpha->tenant), $count($this->bravo->tenant)];

    $this->post('/careers/bravo/field-sales-executive/apply', [
        'full_name' => 'New Applicant', 'email' => 'new.applicant@example.test', 'mobile' => '9822200001', 'current_city' => 'Pune',
        'resume' => UploadedFile::fake()->create('cv.pdf', 60, 'application/pdf'), 'privacy_consent' => '1',
    ])->assertRedirect();

    expect($count($this->bravo->tenant))->toBe($bravoBefore + 1)
        ->and($count($this->alpha->tenant))->toBe($alphaBefore)
        ->and(TenantContext::current()->run($this->bravo->tenant, fn () => CandidateDocument::query()->whereRelation('candidate', 'full_name', 'New Applicant')->sole()->file_path))
        ->toStartWith("tenants/{$this->bravo->tenant->id}/");
});

test('candidate portal accounts are per tenant: the same email is two accounts, each signing in to its own portal only', function (): void {
    TenantContext::current()->run($this->bravo->tenant, fn () => $this->bravo->portalAccount->forceFill(['password' => Hash::make('Bravo-Only-Secret-9')])->save());
    $provider = Auth::guard('candidate')->getProvider();

    expect($this->alpha->portalAccount->email)->toBe($this->bravo->portalAccount->email)
        ->and(TenantContext::current()->run($this->bravo->tenant, fn () => $provider->retrieveById($this->alpha->portalAccount->id)))->toBeNull()
        ->and(TenantContext::current()->run($this->alpha->tenant, fn () => $provider->retrieveById($this->alpha->portalAccount->id)?->is($this->alpha->portalAccount)))->toBeTrue();

    $this->post('/portal/bravo/login', ['email' => 'shared.candidate@example.test', 'password' => 'Correct-Horse-Battery-9']);
    expect(Auth::guard('candidate')->check())->toBeFalse();

    $this->post('/portal/alpha/login', ['email' => 'shared.candidate@example.test', 'password' => 'Correct-Horse-Battery-9']);
    expect(Auth::guard('candidate')->user()?->is($this->alpha->portalAccount))->toBeTrue();

    $this->get('/portal/alpha')->assertOk()->assertSee('APP-2026-500001')->assertDontSee('BRAVO');
});

test('a private file is served only to someone who may act in the signed tenant, for a record of that tenant', function (): void {
    actingAs($this->alpha->chro);

    $own = PrivateFileController::temporaryUrl($this->alpha->document->file_path, now()->addMinutes(5));
    $foreignPathInOwnTenant = PrivateFileController::temporaryUrl($this->bravo->document->file_path, now()->addMinutes(5));
    $foreignTenant = TenantContext::current()->run($this->bravo->tenant, fn () => PrivateFileController::temporaryUrl($this->bravo->document->file_path, now()->addMinutes(5)));

    expect($this->get($own)->assertOk()->streamedContent())->toContain('ALPHA resume');
    $this->get($foreignPathInOwnTenant)->assertNotFound();
    $this->get($foreignTenant)->assertNotFound();
    $this->get(str_replace('tenant='.$this->alpha->tenant->id, 'tenant='.$this->bravo->tenant->id, $own))->assertForbidden();
});

test('another tenant\'s export cannot be downloaded', function (): void {
    $export = TenantContext::current()->run($this->bravo->tenant, function (): Export {
        $export = new Export;
        $export->user()->associate($this->bravo->chro);
        $export->forceFill(['exporter' => 'App\\Filament\\Exports\\CandidateExporter', 'total_rows' => 1, 'file_disk' => 'local', 'file_name' => 'bravo', 'completed_at' => now()])->save();

        return $export;
    });

    actingAs($this->alpha->chro)->get(route('filament.exports.download', ['export' => $export, 'format' => 'csv'], absolute: false))->assertNotFound();
});

test('a provider callback updates the message in its own tenant, and an opt-out reply applies in each tenant holding the number', function (): void {
    config(['services.whatsapp_cloud' => ['token' => 't', 'phone_number_id' => '1', 'app_secret' => 'app-secret', 'verify_token' => 'verify-me', 'api_version' => 'v20.0']]);
    $message = TenantContext::current()->run($this->bravo->tenant, function (): CandidateCommunication {
        $message = CandidateCommunication::factory()->create(['candidate_id' => $this->bravo->candidate->id, 'channel' => CommunicationChannel::WhatsApp]);
        $message->forceFill(['status' => CommunicationStatus::Sent, 'provider' => 'whatsapp_cloud', 'provider_message_id' => 'wamid.bravo.1'])->save();

        return $message;
    });
    $post = function (array $value): void {
        $body = json_encode(['entry' => [['changes' => [['value' => $value]]]]]);
        $this->call('POST', route('webhooks.communications', 'whatsapp_cloud'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret')], $body)->assertOk();
    };

    $post(['statuses' => [['id' => 'wamid.bravo.1', 'status' => 'delivered', 'timestamp' => (string) now()->timestamp]]]);

    expect(TenantContext::current()->run($this->bravo->tenant, fn () => $message->fresh()->status))->toBe(CommunicationStatus::Delivered)
        ->and(CommunicationWebhookEvent::query()->sole()->tenant_id)->toBe($this->bravo->tenant->id);

    $post(['messages' => [['id' => 'wamid.in.1', 'from' => '919876500001', 'timestamp' => (string) now()->timestamp, 'text' => ['body' => 'stop']]]]);

    foreach ([$this->alpha, $this->bravo] as $world) {
        expect(TenantContext::current()->run($world->tenant, fn () => app(CommunicationPreferenceService::class)->statusFor($world->candidate->fresh(), CommunicationChannel::WhatsApp)))
            ->toBe(PreferenceStatus::OptedOut);
    }
});

test('a calendar connection completes only in the tenant it was started from, for a person who may act there', function (): void {
    actingAs($this->alpha->chro)
        ->withSession(['calendar_oauth_state.google_calendar' => ['state' => 'expected', 'tenant_id' => $this->bravo->tenant->id]])
        ->get(route('integrations.calendar.callback', 'google_calendar').'?state=expected&code=abc')
        ->assertForbidden();
});
