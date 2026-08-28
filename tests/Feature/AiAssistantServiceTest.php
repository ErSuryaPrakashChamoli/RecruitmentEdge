<?php

use App\Models\AiKnowledgeArticle;
use App\Models\AiQueryLog;
use App\Models\User;
use App\Services\AiAssistantService;

beforeEach(function (): void {
    $this->service = app(AiAssistantService::class);
});

test('search matches published articles by keyword and ignores unpublished ones', function (): void {
    $match = AiKnowledgeArticle::factory()->create(['title' => 'Notice Period Policy', 'is_published' => true]);
    AiKnowledgeArticle::factory()->create(['title' => 'Unrelated Travel Policy', 'is_published' => true]);
    AiKnowledgeArticle::factory()->create(['title' => 'Notice Period Draft', 'is_published' => false]);

    $results = $this->service->search('What is the notice period?');

    expect($results->pluck('id'))->toContain($match->id)
        ->and($results->pluck('id'))->not->toContain(
            AiKnowledgeArticle::where('title', 'Notice Period Draft')->value('id'),
        );
});

test('ask returns the best match and logs the query with matched article ids', function (): void {
    $user = User::factory()->create();
    $article = AiKnowledgeArticle::factory()->create(['title' => 'Referral Bonus Policy', 'content' => 'Referral bonuses are paid after 90 days.']);

    $result = $this->service->ask('Tell me about the referral bonus', $user->id);

    expect($result['answer'])->toBe($article->content)
        ->and($result['articles']->pluck('id'))->toContain($article->id);

    $log = AiQueryLog::query()->latest('id')->first();

    expect($log->user_id)->toBe($user->id)
        ->and($log->matched_article_ids)->toContain($article->id)
        ->and($log->answer)->toBe($article->content);
});

test('ask falls back to a not-found message and still logs when nothing matches', function (): void {
    $result = $this->service->ask('zzznonexistentqueryzzz');

    expect($result['articles'])->toBeEmpty()
        ->and($result['answer'])->toContain("couldn't find anything");

    $log = AiQueryLog::query()->latest('id')->first();

    expect($log->matched_article_ids)->toBe([])
        ->and($log->user_id)->toBeNull();
});
