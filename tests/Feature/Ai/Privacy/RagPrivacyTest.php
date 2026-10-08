<?php

use App\Filament\Resources\AiDocuments\Pages\CreateAiDocument;
use App\Filament\Resources\AiDocuments\Pages\ListAiDocuments;
use App\Jobs\AI\IndexAiDocumentJob;
use App\Models\AiConversation;
use App\Models\AiDocument;
use App\Models\AiDocumentChunk;
use App\Models\AiKnowledgeArticle;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Orchestrator\ConversationContextBuilder;
use App\Services\AI\Rag\DocumentIngestionService;
use App\Services\AI\Rag\VectorSearch;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Ai\Fakes\RecordingEmbeddingProvider;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
    config(['ai.features.rag_enabled' => true, 'ai.rag.min_similarity' => 0.0]);
    $this->embeddings = new RecordingEmbeddingProvider;
    app()->instance(EmbeddingProviderInterface::class, $this->embeddings);
    $this->admin = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
});

function ragPrivacyDocument(string $text, bool $declared): AiDocument
{
    Storage::disk('local')->put($path = 'ai-documents/'.uniqid().'.txt', $text);

    return AiDocument::withoutEvents(fn () => AiDocument::factory()->create([
        'file_path' => $path,
        'privacy_declared_at' => $declared ? now() : null,
    ]));
}

test('ingestion removes contact details and IDs before embedding or storing, and keeps only a count', function (): void {
    $document = ragPrivacyDocument('Leave policy. Escalate to hr.desk@example.invalid or 9999912345. PAN ABCDE1234F.', declared: true);

    app(DocumentIngestionService::class)->ingestDocument($document);

    $stored = AiDocumentChunk::query()->where('source_id', $document->id)->pluck('content')->implode(' ');

    expect($document->fresh()->pii_redactions)->toBe(3)
        ->and($stored)->toContain('Leave policy')->not->toContain('hr.desk@example.invalid')->not->toContain('9999912345')->not->toContain('ABCDE1234F')
        ->and(implode(' ', $this->embeddings->texts))->not->toContain('hr.desk@example.invalid')->not->toContain('9999912345');
});

test('knowledge articles are scrubbed the same way', function (): void {
    Queue::fake();
    $article = AiKnowledgeArticle::factory()->create(['is_published' => true, 'content' => 'Call the helpdesk on 9999912345.']);

    app(DocumentIngestionService::class)->ingestKnowledgeArticle($article);

    expect(AiDocumentChunk::query()->where('source_type', 'knowledge_article')->value('content'))->not->toContain('9999912345');
});

test('documents without the no-personal-data declaration are never retrieved for the AI', function (): void {
    $declared = ragPrivacyDocument('Notice period policy details', declared: true);
    $undeclared = ragPrivacyDocument('Notice period policy details for legacy upload', declared: false);
    app(DocumentIngestionService::class)->ingestDocument($declared);
    app(DocumentIngestionService::class)->ingestDocument($undeclared);

    $sources = app(VectorSearch::class)->search('notice period')->pluck('source_id')->all();

    expect($sources)->toContain($declared->id)->not->toContain($undeclared->id);
});

test('uploading requires the declaration, which is recorded and audited', function (): void {
    Queue::fake();
    actingAs($this->admin);
    $file = UploadedFile::fake()->createWithContent('policy.txt', 'Policy text');

    Livewire::test(CreateAiDocument::class)
        ->fillForm(['title' => 'Leave policy', 'category' => 'policy', 'file_path' => $file, 'is_published' => true, 'privacy_declaration' => false])
        ->call('create')
        ->assertHasFormErrors(['privacy_declaration' => 'accepted']);

    Livewire::test(CreateAiDocument::class)
        ->fillForm(['title' => 'Leave policy', 'category' => 'policy', 'file_path' => $file, 'is_published' => true, 'privacy_declaration' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $document = AiDocument::query()->sole();

    expect($document->isPrivacyDeclared())->toBeTrue()
        ->and($document->privacy_declared_by)->toBe($this->admin->id)
        ->and(AuditLog::query()->where('action', 'ai_document_privacy_declared')->where('auditable_id', $document->id)->exists())->toBeTrue();
});

test('an administrator can declare a legacy document, which is audited and re-indexed', function (): void {
    Queue::fake();
    $legacy = ragPrivacyDocument('Old policy', declared: false);
    actingAs($this->admin);

    Livewire::test(ListAiDocuments::class)->callTableAction('declarePrivacy', $legacy);

    expect($legacy->fresh()->isPrivacyDeclared())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'ai_document_privacy_declared')->where('user_id', $this->admin->id)->exists())->toBeTrue();
    Queue::assertPushed(IndexAiDocumentJob::class, fn (IndexAiDocumentJob $job) => $job->uniqueId() === (string) $legacy->id);
});

test('chunks stored before the boundary are scrubbed again when they enter a prompt', function (): void {
    $document = ragPrivacyDocument('unused', declared: true);
    $document->forceFill(['status' => 'indexed'])->save();
    AiDocumentChunk::query()->create(['source_type' => 'document', 'source_id' => $document->id, 'chunk_index' => 0, 'content' => 'Legacy chunk: contact legacy.person@example.invalid', 'embedding' => [1.0], 'token_count' => 4]);
    $conversation = AiConversation::factory()->create(['user_id' => $this->admin->id]);

    $prompt = json_encode(array_map(fn ($m) => $m->content, app(ConversationContextBuilder::class)->build($conversation, 'legacy chunk')));

    expect($prompt)->toContain('Legacy chunk')->not->toContain('legacy.person@example.invalid');
});
