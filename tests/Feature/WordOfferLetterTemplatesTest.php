<?php

use App\Enums\OfferLetterTemplateFormat;
use App\Enums\OfferStatus;
use App\Filament\Resources\OfferLetterTemplates\Pages\CreateOfferLetterTemplate;
use App\Filament\Resources\OfferLetterTemplates\Pages\EditOfferLetterTemplate;
use App\Filament\Resources\Offers\Pages\EditOffer;
use App\Jobs\ConvertOfferLetterJob;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\OfferLetter;
use App\Models\OfferLetterConversion;
use App\Models\OfferLetterTemplate;
use App\Models\User;
use App\Notifications\StaffDatabaseNotification;
use App\Services\OfferLetterIssuanceService;
use App\Services\OfferLetterRenderer;
use App\Services\OfferService;
use App\Services\WordToPdfConverter;
use Database\Seeders\RecruitmentReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\TemplateProcessor;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);
    $this->seed(RecruitmentReferenceDataSeeder::class);

    $this->standard = OfferLetterTemplate::systemTemplate();

    $this->admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $this->admin->assignRole('chro');
    actingAs($this->admin);
});

/**
 * A .docx upload whose single paragraph is the given text.
 */
function wordUploadWith(string $text): UploadedFile
{
    $phpWord = new PhpWord;
    $phpWord->addSection()->addText($text);

    $path = sys_get_temp_dir().'/'.uniqid('word-upload-').'.docx';
    IOFactory::createWriter($phpWord, 'Word2007')->save($path);

    return UploadedFile::fake()->createWithContent('offer-letter.docx', (string) file_get_contents($path));
}

function offerForWordLetter(): Offer
{
    $application = CandidateApplication::factory()->create();
    $application->candidate->update(['full_name' => 'Asha Verma']);

    return Offer::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => OfferStatus::Initiated,
        'offered_ctc' => 1200000,
    ]);
}

test('the seeder creates the protected standard Word template as the default', function (): void {
    expect($this->standard)->not->toBeNull()
        ->and($this->standard->format)->toBe(OfferLetterTemplateFormat::Word)
        ->and($this->standard->is_default)->toBeTrue()
        ->and($this->standard->hasFile())->toBeTrue()
        ->and(app(OfferLetterRenderer::class)->unknownPlaceholdersIn($this->standard->absoluteFilePath()))->toBe([]);
});

test('the standard template cannot be deleted or deactivated', function (): void {
    expect($this->admin->can('delete', $this->standard))->toBeFalse();
    expect(fn () => $this->standard->delete())->toThrow(DomainException::class);
    expect(fn () => $this->standard->update(['is_active' => false]))->toThrow(DomainException::class);

    Livewire::test(EditOfferLetterTemplate::class, ['record' => $this->standard->getKey()])
        ->assertActionHidden('delete')
        ->assertActionVisible('restoreOriginal');

    expect(OfferLetterTemplate::systemTemplate()?->is_active)->toBeTrue();
});

test('an admin can download the Word template file', function (): void {
    Livewire::test(EditOfferLetterTemplate::class, ['record' => $this->standard->getKey()])
        ->callAction('downloadWordFile')
        ->assertFileDownloaded('standard-offer-letter.docx');
});

test('uploading an edited Word file replaces the template file and keeps the previous one as a version', function (): void {
    $previousPath = $this->standard->file_path;

    Livewire::test(EditOfferLetterTemplate::class, ['record' => $this->standard->getKey()])
        ->callAction('uploadWordFile', data: ['file' => wordUploadWith('Welcome aboard, ${candidate_name}!')])
        ->assertNotified('Word template updated');

    $template = $this->standard->fresh();

    expect($template->file_path)->not->toBe($previousPath)
        // Phase 8.6 (D8.6-011): the superseded file is kept — issued letters may refer to it.
        ->and(Storage::disk('local')->exists($previousPath))->toBeTrue()
        ->and($template->versions()->pluck('file_path')->all())->toBe([$previousPath, $template->file_path])
        ->and((new TemplateProcessor($template->absoluteFilePath()))->getVariables())->toBe(['candidate_name']);
});

test('a Word file with placeholders an offer cannot fill is rejected', function (): void {
    $previousPath = $this->standard->file_path;

    Livewire::test(EditOfferLetterTemplate::class, ['record' => $this->standard->getKey()])
        ->callAction('uploadWordFile', data: ['file' => wordUploadWith('Your band is ${salary_band}')])
        ->assertNotified('Unknown placeholders in the Word file');

    expect($this->standard->fresh()->file_path)->toBe($previousPath);
});

test('restore original brings back the standard Word file', function (): void {
    Livewire::test(EditOfferLetterTemplate::class, ['record' => $this->standard->getKey()])
        ->callAction('uploadWordFile', data: ['file' => wordUploadWith('Short letter for ${candidate_name}')]);

    Livewire::test(EditOfferLetterTemplate::class, ['record' => $this->standard->getKey()])
        ->callAction('restoreOriginal')
        ->assertNotified('Original offer letter restored');

    expect((new TemplateProcessor($this->standard->fresh()->absoluteFilePath()))->getVariables())
        ->toContain('offered_ctc', 'expected_joining_date');
});

test('an admin can create a new Word template by uploading a file', function (): void {
    Livewire::test(CreateOfferLetterTemplate::class)
        ->fillForm([
            'name' => 'Sales Offer Letter',
            'format' => OfferLetterTemplateFormat::Word->value,
            'file_path' => wordUploadWith('Dear ${candidate_name}, welcome to the sales team.'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $template = OfferLetterTemplate::query()->where('name', 'Sales Offer Letter')->firstOrFail();

    expect($template->isWord())->toBeTrue()
        ->and($template->hasFile())->toBeTrue();
});

test('a Word template is filled from the offer and sent to the candidate as a PDF', function (): void {
    $converter = new class extends WordToPdfConverter
    {
        public string $documentXml = '';

        public function convert(string $docxPath): string
        {
            $zip = new ZipArchive;
            $zip->open($docxPath);
            $this->documentXml = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            return '%PDF-converted';
        }
    };
    app()->instance(WordToPdfConverter::class, $converter);

    $offer = offerForWordLetter();
    $renderer = app(OfferLetterRenderer::class);

    expect($renderer->sourceFor($offer))->toBe('word')
        ->and($renderer->pdf($offer))->toBe('%PDF-converted')
        ->and($converter->documentXml)->toContain('Asha Verma')
        ->toContain('1,200,000.00')
        ->not->toContain('${');
});

test('without LibreOffice the Word letter is still delivered as a PDF', function (): void {
    Process::fake();

    $pdf = app(OfferLetterRenderer::class)->pdf(offerForWordLetter());

    expect($pdf)->toStartWith('%PDF');
    Process::assertRan(fn (PendingProcess $process): bool => in_array('--convert-to', (array) $process->command, true));
});

test('releasing a Word-letter offer never converts in the request: the filled letter is fixed at release and the PDF issued by a queued job (Phase 8.9)', function (): void {
    Queue::fake();
    app()->instance(WordToPdfConverter::class, new class extends WordToPdfConverter
    {
        public function convert(string $docxPath): string
        {
            throw new LogicException('The release request must not convert the letter.');
        }
    });
    $offer = offerForWordLetter();
    $releaser = Employee::factory()->create();
    User::factory()->create(['employee_id' => $releaser->id])->assignRole('chro');

    app(OfferService::class)->moveTo($offer, OfferStatus::Released, $releaser);

    $conversion = OfferLetterConversion::query()->sole();
    expect($conversion->status)->toBe(OfferLetterConversion::PENDING)
        ->and(OfferLetter::query()->where('offer_id', $offer->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($conversion->document_path))->toBeTrue();
    Queue::assertPushedOn('documents', ConvertOfferLetterJob::class);

    // Data changed after the release does not change the letter the candidate receives.
    $offer->candidateApplication->candidate->update(['full_name' => 'Changed Name']);
    $converter = new class extends WordToPdfConverter
    {
        public string $documentXml = '';

        public function convert(string $docxPath): string
        {
            $zip = new ZipArchive;
            $zip->open($docxPath);
            $this->documentXml = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            return '%PDF-converted';
        }
    };
    app()->instance(WordToPdfConverter::class, $converter);

    app()->call([new ConvertOfferLetterJob($conversion->id), 'handle']);

    $letter = OfferLetter::query()->where('offer_id', $offer->id)->sole();
    expect($converter->documentXml)->toContain('Asha Verma')->not->toContain('Changed Name')
        ->and($letter->sha256)->toBe(hash('sha256', '%PDF-converted'))
        ->and($letter->issued_at->equalTo($conversion->requested_at))->toBeTrue()
        ->and($conversion->fresh()->status)->toBe(OfferLetterConversion::ISSUED)
        ->and(AuditLog::query()->where('action', 'offer_letter_issued')->where('auditable_id', $offer->id)->exists())->toBeTrue();
});

test('a letter still being converted is never answered with an older one, and a failed conversion alerts the releaser (Phase 8.9)', function (): void {
    Queue::fake();
    $offer = offerForWordLetter();
    $releaser = Employee::factory()->create();
    $releaserUser = User::factory()->create(['employee_id' => $releaser->id])->assignRole('chro');
    app(OfferService::class)->moveTo($offer, OfferStatus::Released, $releaser);
    $letters = app(OfferLetterIssuanceService::class);

    expect($letters->pendingConversionFor($offer))->not->toBeNull();

    $conversion = OfferLetterConversion::query()->sole();
    Notification::fake();
    (new ConvertOfferLetterJob($conversion->id))->failed(new RuntimeException('soffice crashed for asha@example.com'));

    expect($conversion->fresh()->status)->toBe(OfferLetterConversion::FAILED)
        ->and($conversion->fresh()->error)->not->toContain('asha@example.com')
        ->and($letters->pendingConversionFor($offer))->toBeNull()
        ->and(AuditLog::query()->where('action', 'offer_letter_conversion_failed')->exists())->toBeTrue();
    Notification::assertSentTo($releaserUser, StaffDatabaseNotification::class);
});

test('downloading a letter while its PDF is still being produced says so instead of failing (Phase 8.9)', function (): void {
    Queue::fake();
    $offer = offerForWordLetter();
    app(OfferService::class)->moveTo($offer, OfferStatus::Released, $this->admin->employee);

    Livewire::test(EditOffer::class, ['record' => $offer->getRouteKey()])
        ->callAction('downloadOfferLetter')
        ->assertNotified('Offer letter being prepared')
        ->assertNoFileDownloaded();
});
