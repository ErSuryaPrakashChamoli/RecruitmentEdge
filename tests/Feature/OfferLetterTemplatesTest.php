<?php

use App\Enums\OfferStatus;
use App\Filament\Resources\OfferLetterTemplates\OfferLetterTemplateResource;
use App\Filament\Resources\OfferLetterTemplates\Pages\CreateOfferLetterTemplate;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\OfferLetterTemplate;
use App\Models\User;
use App\Services\OfferLetterRenderer;
use Database\Seeders\RecruitmentReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);

    $this->recruiter = Employee::factory()->create();
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id]);
    $this->application->candidate->update(['full_name' => 'Asha Verma']);
    $this->offer = Offer::factory()->create([
        'candidate_application_id' => $this->application->id,
        'status' => OfferStatus::Initiated,
        'offered_ctc' => 1200000,
    ]);
});

function actingAsLetterUser(Employee $employee, string $role): User
{
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $user->assignRole($role);
    actingAs($user);

    return $user;
}

test('merge tags in the letter are filled from the offer', function (): void {
    OfferLetterTemplate::factory()->default()->create();

    $html = app(OfferLetterRenderer::class)->renderBody($this->offer);

    expect(strip_tags($html))->toBe('Dear Asha Verma, your offered CTC is ₹1,200,000.00.')
        ->and($html)->not->toContain('"></span>');
});

test('the letter body comes from the offer, then its template, then the default, then the built-in letter', function (): void {
    $renderer = app(OfferLetterRenderer::class);

    expect($renderer->bodyFor($this->offer))->toBeNull()
        ->and($renderer->sourceFor($this->offer))->toBe('built_in');

    $default = OfferLetterTemplate::factory()->default()->create(['body' => '<p>Default wording</p>']);
    expect($renderer->bodyFor($this->offer->fresh()))->toBe($default->body)
        ->and($renderer->sourceFor($this->offer->fresh()))->toBe('rich_text');

    $chosen = OfferLetterTemplate::factory()->create(['body' => '<p>Chosen wording</p>']);
    $this->offer->update(['offer_letter_template_id' => $chosen->id]);
    expect($renderer->bodyFor($this->offer->fresh()))->toBe($chosen->body);

    $chosen->update(['is_active' => false]);
    expect($renderer->bodyFor($this->offer->fresh()))->toBe($default->body);

    $this->offer->update(['offer_letter_body' => '<p>Tailored wording</p>']);
    expect($renderer->bodyFor($this->offer->fresh()))->toBe('<p>Tailored wording</p>')
        ->and($renderer->sourceFor($this->offer->fresh()))->toBe('custom');
});

test('saving a template as default clears the previous default', function (): void {
    $first = OfferLetterTemplate::factory()->default()->create();
    $second = OfferLetterTemplate::factory()->default()->create();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and(OfferLetterTemplate::defaultTemplate()?->is($second))->toBeTrue();
});

test('only users with settings.manage can manage offer letter templates', function (): void {
    actingAsLetterUser($this->recruiter, 'manager');
    $this->get(OfferLetterTemplateResource::getUrl('index'))->assertForbidden();

    actingAsLetterUser(Employee::factory()->create(), 'chro');
    $this->get(OfferLetterTemplateResource::getUrl('index'))->assertSuccessful();

    Livewire::test(CreateOfferLetterTemplate::class)
        ->fillForm([
            'name' => 'Sales Offer Letter',
            'body' => '<p>Welcome <span data-type="mergeTag" data-id="candidate_name"></span></p>',
            'is_default' => true,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(OfferLetterTemplate::defaultTemplate()?->name)->toBe('Sales Offer Letter');
});

test('an offer owner can tailor the letter for one offer and reset it to the template', function (): void {
    $template = OfferLetterTemplate::factory()->default()->create();
    actingAsLetterUser($this->recruiter, 'manager');

    Livewire::test(EditOffer::class, ['record' => $this->offer->getKey()])
        ->callAction('customizeOfferLetter', data: [
            'offer_letter_template_id' => $template->id,
            'offer_letter_body' => '<p>Special joining terms for <span data-type="mergeTag" data-id="candidate_name"></span></p>',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Offer letter saved');

    expect($this->offer->fresh()->offer_letter_body)->toContain('Special joining terms')
        ->and(strip_tags(app(OfferLetterRenderer::class)->renderBody($this->offer->fresh())))->toBe('Special joining terms for Asha Verma');

    Livewire::test(EditOffer::class, ['record' => $this->offer->getKey()])
        ->callAction('resetOfferLetter')
        ->assertNotified('Offer letter reset to template');

    expect($this->offer->fresh()->offer_letter_body)->toBeNull();
});

test('the letter cannot be customised once the candidate has decided on the offer', function (): void {
    $this->offer->update(['status' => OfferStatus::Accepted]);
    actingAsLetterUser($this->recruiter, 'manager');

    Livewire::test(EditOffer::class, ['record' => $this->offer->getKey()])
        ->assertActionHidden('customizeOfferLetter');
});

test('downloading an offer letter renders the template-based PDF', function (): void {
    OfferLetterTemplate::factory()->default()->create();
    actingAsLetterUser($this->recruiter, 'manager');

    Livewire::test(EditOffer::class, ['record' => $this->offer->getKey()])
        ->callAction('downloadOfferLetter')
        ->assertFileDownloaded("offer-letter-{$this->offer->offer_code}.pdf");
});

test('the reference data seeder adds one default template and never replaces an admin default', function (): void {
    $this->seed(RecruitmentReferenceDataSeeder::class);
    $this->seed(RecruitmentReferenceDataSeeder::class);

    expect(OfferLetterTemplate::query()->count())->toBe(1)
        ->and(OfferLetterTemplate::defaultTemplate()?->name)->toBe('Standard Offer Letter');

    OfferLetterTemplate::query()->delete();
    OfferLetterTemplate::factory()->default()->create(['name' => 'Our Letter']);
    $this->seed(RecruitmentReferenceDataSeeder::class);

    expect(OfferLetterTemplate::defaultTemplate()?->name)->toBe('Our Letter')
        ->and(OfferLetterTemplate::systemTemplate()?->is_default)->toBeFalse();
});
