<?php

use App\Enums\CandidateStage;
use App\Enums\OfferLetterTemplateFormat;
use App\Enums\OfferStatus;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\OfferLetter;
use App\Models\OfferLetterTemplate;
use App\Models\User;
use App\Services\OfferLetterIssuanceService;
use App\Services\OfferService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.6 offer letter integrity (D8.6-010/011): the letter is issued once at release (and at
 * each revision release), stored with its hash and template version, and served as issued — a
 * later template edit, Word re-upload or default change never alters it.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);
    $this->releaserEmployee = Employee::factory()->create();
    $this->releaser = User::factory()->create(['employee_id' => $this->releaserEmployee->id])->assignRole('chro');
    actingAs($this->releaser);
    $this->template = OfferLetterTemplate::factory()->create(['body' => '<p>ORIGINAL WORDING for <span data-type="mergeTag" data-id="candidate_name"></span></p>', 'is_active' => true]);
    $this->service = app(OfferService::class);
});

function releasedLetterOffer(OfferLetterTemplate $template): Offer
{
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);
    $offer = test()->service->create(['offer_code' => 'OFR-LTR-'.fake()->unique()->numerify('####'), 'candidate_application_id' => $application->id, 'offer_date' => now(), 'offered_ctc' => 1200000, 'offer_letter_template_id' => $template->id], test()->releaserEmployee);
    test()->service->moveTo($offer, OfferStatus::Initiated, test()->releaserEmployee);

    return test()->service->moveTo($offer->fresh(), OfferStatus::Released, test()->releaserEmployee);
}

test('FAILURE 3: an issued offer letter does not change when its template is edited afterwards', function (): void {
    $offer = releasedLetterOffer($this->template);
    $letter = OfferLetter::query()->where('offer_id', $offer->id)->sole();
    $issuedBytes = Storage::disk('local')->get($letter->file_path);

    $this->template->update(['body' => '<p>NEW WORDING</p>']);

    ['pdf' => $served, 'issued' => $issued] = app(OfferLetterIssuanceService::class)->pdfFor($offer->fresh());

    expect($letter->revision)->toBe(1)
        ->and($letter->source)->toBe('rich_text')
        ->and($letter->sha256)->toBe(hash('sha256', $issuedBytes))
        ->and($letter->issued_by)->toBe($this->releaserEmployee->id)
        ->and($issued?->id)->toBe($letter->id)
        ->and(hash('sha256', $served))->toBe($letter->sha256)
        ->and($letter->templateVersion->body)->toContain('ORIGINAL WORDING')
        ->and($this->template->fresh()->versions()->orderByDesc('version')->first()->body)->toContain('NEW WORDING');

    Livewire::test(EditOffer::class, ['record' => $offer->getRouteKey()])
        ->callAction('downloadOfferLetter')
        ->assertFileDownloaded("offer-letter-{$offer->offer_code}-r1.pdf");
});

test('an issued letter and a template version can never be changed or deleted', function (): void {
    $offer = releasedLetterOffer($this->template);
    $letter = OfferLetter::query()->where('offer_id', $offer->id)->sole();
    $version = $letter->templateVersion;

    expect(fn () => $letter->update(['sha256' => str_repeat('0', 64)]))->toThrow(LogicException::class)
        ->and(fn () => $letter->delete())->toThrow(LogicException::class)
        ->and(fn () => $version->update(['body' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $version->delete())->toThrow(LogicException::class);
});

test('releasing a revision issues a new letter and keeps the original', function (): void {
    $offer = releasedLetterOffer($this->template);
    $revision = $this->service->requestRevision($offer, ['offered_ctc' => 1500000], 'Counter offer matched', $this->releaser);
    $this->service->releaseRevision($revision, $this->releaser);

    $letters = OfferLetter::query()->where('offer_id', $offer->id)->orderBy('id')->get();

    expect($letters->pluck('revision')->all())->toBe([1, 2])
        ->and($letters[1]->offer_revision_id)->toBe($revision->id)
        ->and(Storage::disk('local')->exists($letters[0]->file_path))->toBeTrue()
        ->and(app(OfferLetterIssuanceService::class)->latestFor($offer)->id)->toBe($letters[1]->id);
});

test('a template used by an offer cannot be deleted, only deactivated', function (): void {
    releasedLetterOffer($this->template);

    expect($this->releaser->can('delete', $this->template->fresh()))->toBeFalse()
        ->and(fn () => $this->template->fresh()->delete())->toThrow(DomainException::class, 'deactivate it instead')
        ->and($this->releaser->can('deleteAny', OfferLetterTemplate::class))->toBeFalse();

    $unused = OfferLetterTemplate::factory()->create();
    expect($this->releaser->can('delete', $unused))->toBeTrue();
});

test('re-uploading a Word template keeps the superseded file and versions both', function (): void {
    Storage::disk('local')->put('offer-letter-templates/v1.docx', 'first file');
    Storage::disk('local')->put('offer-letter-templates/v2.docx', 'second file');
    $word = OfferLetterTemplate::factory()->create(['format' => OfferLetterTemplateFormat::Word, 'body' => null, 'file_path' => 'offer-letter-templates/v1.docx']);

    $word->update(['file_path' => 'offer-letter-templates/v2.docx']);

    $versions = $word->fresh()->versions()->orderBy('version')->get();

    expect($versions->pluck('file_path')->all())->toBe(['offer-letter-templates/v1.docx', 'offer-letter-templates/v2.docx'])
        ->and($word->fresh()->version)->toBe(2)
        ->and(Storage::disk('local')->exists('offer-letter-templates/v1.docx'))->toBeTrue();
});

test('changing the default template is audited for both templates', function (): void {
    $first = OfferLetterTemplate::factory()->create(['is_default' => true]);
    $second = OfferLetterTemplate::factory()->create(['is_default' => false]);

    $second->update(['is_default' => true]);

    $cleared = AuditLog::query()->where('auditable_type', OfferLetterTemplate::class)->where('auditable_id', $first->id)->where('action', 'updated')->sole();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($cleared->changes)->toBe(['is_default' => false]);
});

test('an offer released before letters were kept is regenerated and labelled as such', function (): void {
    $legacy = lifecycleFixture(fn () => Offer::factory()->create(['status' => OfferStatus::Released]));

    ['issued' => $issued] = app(OfferLetterIssuanceService::class)->pdfFor($legacy);

    expect($issued)->toBeNull()
        ->and(OfferLetter::query()->where('offer_id', $legacy->id)->exists())->toBeFalse();

    Livewire::test(EditOffer::class, ['record' => $legacy->getRouteKey()])
        ->callAction('downloadOfferLetter')
        ->assertNotified('Regenerated letter');
});

test('a stored letter that fails its integrity check is never served as issued', function (): void {
    $offer = releasedLetterOffer($this->template);
    $letter = OfferLetter::query()->where('offer_id', $offer->id)->sole();
    Storage::disk('local')->put($letter->file_path, 'tampered');

    expect(app(OfferLetterIssuanceService::class)->pdfFor($offer)['issued'])->toBeNull();
});
