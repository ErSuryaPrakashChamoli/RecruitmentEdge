<?php

use App\Enums\AiMessageRole;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AuditLog;
use App\Models\User;

beforeEach(function (): void {
    $this->conversation = AiConversation::factory()->create(['user_id' => User::factory()->create()->id]);
    $this->conversation->forceFill(['privacy_version' => null])->save();
    $this->conversation->messages()->create(['role' => AiMessageRole::User, 'content' => 'Email PRIVATE-ALICE@example.invalid']);
    $this->conversation->messages()->create(['role' => AiMessageRole::Tool, 'tool_call_id' => 'x', 'tool_name' => 'get_candidate', 'content' => json_encode(['data' => ['full_name' => 'PRIVATE-CANDIDATE-ALICE', 'expected_salary' => 99999999]])]);
    $this->conversation->messages()->create(['role' => AiMessageRole::Assistant, 'content' => 'All clear.']);
});

test('without --dry-run the command refuses and changes nothing', function (): void {
    $before = AiMessage::query()->orderBy('id')->pluck('content')->all();

    $this->artisan('ai:redact-history')->expectsOutputToContain('Only a dry run is available')->assertFailed();

    expect(AiMessage::query()->orderBy('id')->pluck('content')->all())->toBe($before)
        ->and(AuditLog::query()->where('action', 'ai_history_redaction_dry_run')->exists())->toBeFalse();
});

test('a dry run reports affected records and categories, changes nothing, and is audited with counts only', function (): void {
    $before = AiMessage::query()->orderBy('id')->pluck('content')->all();

    $this->artisan('ai:redact-history', ['--dry-run' => true])
        ->expectsOutputToContain('dry run — nothing was changed')
        ->expectsTable(['Measure', 'Count'], [
            ['Conversations affected', 1],
            ['Legacy conversations (before Phase 8.1)', 1],
            ['Messages affected', 2],
            ['Tool calls affected', 0],
            ['Tool results affected', 0],
            ['Action logs affected', 0],
        ])
        ->assertSuccessful();

    $audit = AuditLog::query()->where('action', 'ai_history_redaction_dry_run')->sole();

    expect(AiMessage::query()->orderBy('id')->pluck('content')->all())->toBe($before)
        ->and($audit->changes['messages'])->toBe(2)
        ->and($audit->changes['categories'])->toMatchArray(['email' => 1, 'field' => 2])
        ->and(json_encode($audit->changes))->not->toContain('PRIVATE');
});
