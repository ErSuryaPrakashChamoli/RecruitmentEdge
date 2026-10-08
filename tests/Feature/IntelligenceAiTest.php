<?php

use App\Enums\IntelligenceAiStatus;
use App\Enums\RoleDnaOrigin;
use App\Jobs\GenerateRoleDnaSuggestionsJob;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\HiringMemoryRecord;
use App\Models\User;
use App\Services\AI\DTO\LlmMessage;
use App\Services\AI\DTO\LlmResponse;
use App\Services\AI\Exceptions\AiProviderUnavailableException;
use App\Services\AI\Gateway\AiGateway;
use App\Services\Intelligence\HiringMemoryService;
use App\Services\Intelligence\IntelligenceAiService;
use App\Services\Intelligence\RoleDnaService;
use App\Services\Intelligence\TalentSignalService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->user = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('vp_hr');
    $this->application = CandidateApplication::factory()->create();
    $this->application->candidate->update(['full_name' => 'Priya Secretname', 'email' => 'priya@private.example', 'mobile' => '9876500000']);
    $this->requisition = $this->application->requisition;
    $this->requisition->update(['skills' => ['PHP']]);
    $this->profile = app(RoleDnaService::class)->profileFor($this->requisition);
    app(RoleDnaService::class)->currentVersionFor($this->requisition);
});

/**
 * @param  array<string, mixed>  $structured
 */
function fakeIntelligenceGateway(array $structured = [], ?string $text = null, bool $configured = true, ?Throwable $throws = null): AiGateway
{
    $fake = new class($structured, $text, $configured, $throws) extends AiGateway
    {
        /** @var array<int, array<int, LlmMessage>> */
        public array $prompts = [];

        public function __construct(public array $structured, public ?string $text, public bool $configured, public ?Throwable $throws) {}

        public function isConfigured(): bool
        {
            return $this->configured;
        }

        public function structured(array $messages, array $jsonSchema, string $category = 'extraction', ?User $user = null, ?int $conversationId = null): array
        {
            $this->prompts[] = $messages;

            if ($this->throws !== null) {
                throw $this->throws;
            }

            return $this->structured;
        }

        public function generate(array $messages, array $tools, string $category, ?User $user = null, ?int $conversationId = null): LlmResponse
        {
            $this->prompts[] = $messages;

            return new LlmResponse($this->text, [], [], 'fake-model');
        }
    };

    app()->instance(AiGateway::class, $fake);

    return $fake;
}

test('without an AI provider the request is marked unavailable and nothing is queued', function (): void {
    Queue::fake();
    fakeIntelligenceGateway(configured: false);

    expect(app(IntelligenceAiService::class)->requestRoleDnaSuggestions($this->profile, $this->user))->toBe(IntelligenceAiStatus::Unavailable)
        ->and($this->profile->fresh()->ai_status)->toBe(IntelligenceAiStatus::Unavailable);
    Queue::assertNothingPushed();
});

test('a request is queued on the intelligence queue', function (): void {
    Queue::fake();
    fakeIntelligenceGateway();

    app(IntelligenceAiService::class)->requestRoleDnaSuggestions($this->profile, $this->user);

    expect($this->profile->fresh()->ai_status)->toBe(IntelligenceAiStatus::Processing);
    Queue::assertPushedOn('intelligence', GenerateRoleDnaSuggestionsJob::class);
});

test('valid suggestions are stored unconfirmed; unfair, unsafe and oversized ones are rejected', function (): void {
    fakeIntelligenceGateway([
        'skills' => [
            ['name' => 'Docker', 'level' => 'required', 'reason' => 'Deployments'],
            ['name' => 'PHP Programming', 'level' => 'required', 'reason' => 'Restates the configured PHP skill'],
            ['name' => 'Young and energetic', 'level' => 'required', 'reason' => 'Team fit'],
            ['name' => str_repeat('x', 120), 'level' => 'preferred', 'reason' => 'Too long'],
            ['name' => 'See https://example.com', 'level' => 'preferred', 'reason' => 'link'],
        ],
        'behavioral_indicators' => [['name' => 'Ownership of deliveries', 'reason' => 'Observable'], ['name' => 'Good culture fit', 'reason' => 'x']],
        'interview_dimensions' => [['name' => 'System design', 'reason' => 'Core skill']],
        'responsibilities' => [['name' => 'Native speaker of English', 'reason' => 'x']],
    ]);

    app(IntelligenceAiService::class)->generateRoleDnaSuggestions($this->profile, $this->user);

    $version = $this->profile->fresh()->currentVersion;
    $suggested = $version->pendingSuggestions()->pluck('label')->all();

    expect($suggested)->toBe(['Docker', 'Ownership of deliveries', 'System design'])
        ->and($version->effectiveAttributes()->pluck('label'))->not->toContain('Docker')
        ->and($version->ai_model)->not->toBeNull()
        ->and($version->evidence()->where('subject_key', 'skill:docker')->sole()->ai_model)->not->toBeNull()
        ->and(collect($version->dna)->where('origin', RoleDnaOrigin::AiSuggestion->value)->count())->toBe(3)
        ->and($this->profile->fresh()->ai_status)->toBe(IntelligenceAiStatus::Available);
});

test('the prompt carries role data only — never candidate names or contact details', function (): void {
    $fake = fakeIntelligenceGateway(['skills' => []]);

    app(IntelligenceAiService::class)->generateRoleDnaSuggestions($this->profile, $this->user);
    $prompt = json_encode($fake->prompts);

    expect($prompt)->toContain('PHP')
        ->not->toContain('Priya')
        ->not->toContain('priya@private.example')
        ->not->toContain('9876500000');
});

test('an AI failure is recorded as failed and never shown as intelligence', function (): void {
    fakeIntelligenceGateway(throws: new RuntimeException('provider down'));
    $before = $this->profile->fresh()->current_version;

    app(IntelligenceAiService::class)->generateRoleDnaSuggestions($this->profile, $this->user);

    expect($this->profile->fresh()->ai_status)->toBe(IntelligenceAiStatus::Failed)
        ->and($this->profile->fresh()->current_version)->toBe($before);
});

test('memory summaries are AI-labelled, keep the model, and exclude identifiers', function (): void {
    $record = app(HiringMemoryService::class)->captureRejection($this->application->fresh());
    $fake = fakeIntelligenceGateway(text: 'A candidate was not progressed at the sourcing stage.');

    app(IntelligenceAiService::class)->summarizeMemory($record, $this->user);

    expect($record->fresh())
        ->ai_status->toBe(IntelligenceAiStatus::Available)
        ->ai_summary->toContain('not progressed')
        ->ai_model->not->toBeNull()
        ->and(json_encode($fake->prompts))->not->toContain($this->application->application_code)
        ->and($record->fresh()->facts)->toBeJsonEquivalent($record->facts);
});

test('deterministic intelligence works with no AI at all', function (): void {
    fakeIntelligenceGateway(configured: false);

    expect(app(TalentSignalService::class)->refresh($this->application->fresh())->band)->not->toBeNull()
        ->and(HiringMemoryRecord::query()->count())->toBe(0);
});

test('a provider outage is retried by the queue before the request is marked failed', function (): void {
    fakeIntelligenceGateway(throws: new AiProviderUnavailableException('Gemini returned HTTP 503.'));

    expect(fn () => app(IntelligenceAiService::class)->generateRoleDnaSuggestions($this->profile, $this->user, retryable: true))
        ->toThrow(AiProviderUnavailableException::class);

    app(IntelligenceAiService::class)->generateRoleDnaSuggestions($this->profile, $this->user, retryable: false);

    expect($this->profile->fresh()->ai_status)->toBe(IntelligenceAiStatus::Failed)
        ->and($this->profile->fresh()->ai_error)->toContain('unavailable');
});

test('with a synchronous queue a provider outage never fails the web request', function (): void {
    config(['queue.default' => 'sync']);
    fakeIntelligenceGateway(throws: new AiProviderUnavailableException('Gemini returned HTTP 503.'));

    $status = app(IntelligenceAiService::class)->requestRoleDnaSuggestions($this->profile, $this->user);

    expect($status)->toBe(IntelligenceAiStatus::Failed)
        ->and($this->profile->fresh()->ai_status)->toBe(IntelligenceAiStatus::Failed);
});
