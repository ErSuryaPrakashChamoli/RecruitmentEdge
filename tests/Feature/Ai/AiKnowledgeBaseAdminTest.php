<?php

use App\Filament\Resources\AiKnowledgeArticles\Pages\ListAiKnowledgeArticles;
use App\Jobs\AI\ReindexKnowledgeArticleJob;
use App\Models\AiKnowledgeArticle;
use App\Models\User;
use App\Services\AI\Rag\Parsers\DocxParser;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->article = AiKnowledgeArticle::factory()->create(['is_published' => true]);
});

test('anyone with ai.query can read knowledge articles, but only ai.manage can create, edit, or delete them', function (): void {
    $recruiter = User::factory()->create();
    $recruiter->assignRole('recruiter');

    $admin = User::factory()->create();
    $admin->assignRole('chro');

    expect($recruiter->can('viewAny', AiKnowledgeArticle::class))->toBeTrue()
        ->and($recruiter->can('view', $this->article))->toBeTrue()
        ->and($recruiter->can('create', AiKnowledgeArticle::class))->toBeFalse()
        ->and($recruiter->can('update', $this->article))->toBeFalse()
        ->and($recruiter->can('delete', $this->article))->toBeFalse()
        ->and($admin->can('create', AiKnowledgeArticle::class))->toBeTrue()
        ->and($admin->can('update', $this->article))->toBeTrue()
        ->and($admin->can('delete', $this->article))->toBeTrue();
});

test('an ai.manage user can queue a re-index of a single article from the table', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('chro');
    actingAs($admin);

    Queue::fake();

    Livewire::test(ListAiKnowledgeArticles::class)
        ->callAction(TestAction::make('reindex')->table($this->article));

    Queue::assertPushed(ReindexKnowledgeArticleJob::class, 1);
});

test('the re-index action is hidden from users without ai.manage', function (): void {
    $recruiter = User::factory()->create();
    $recruiter->assignRole('recruiter');
    actingAs($recruiter);

    Livewire::test(ListAiKnowledgeArticles::class)
        ->assertActionHidden(TestAction::make('reindex')->table($this->article));
});

test('the document parser accepts legacy .doc files alongside .docx', function (): void {
    $parser = new DocxParser;

    expect($parser->supports('application/msword', 'doc'))->toBeTrue()
        ->and($parser->supports('', 'docx'))->toBeTrue()
        ->and($parser->supports('application/pdf', 'pdf'))->toBeFalse();
});
