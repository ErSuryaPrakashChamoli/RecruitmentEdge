<?php

namespace App\Console\Commands;

use App\Enums\AiMessageRole;
use App\Models\AiActionLog;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiToolCall;
use App\Models\AiToolResult;
use App\Models\AuditLog;
use App\Services\AI\Privacy\AiPayloadSanitizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Phase 8.1 — DRY RUN ONLY. Reports how much stored AI history (conversations, messages, tool
 * calls and results, action logs) contains personal data the privacy boundary now removes:
 * prohibited fields (names, contacts, pay, remarks) and PII patterns. It never changes a record;
 * actual redaction needs a separate, explicitly approved phase. Names inside free text cannot be
 * detected by pattern and are not counted. The run itself is audited (counts only).
 */
#[Signature('ai:redact-history {--dry-run : Report only — the only mode available} {--limit=20 : How many record ids to list for review}')]
#[Description('Report stored AI history that contains personal data (dry run only; changes nothing)')]
class AiRedactHistoryCommand extends Command
{
    public function handle(AiPayloadSanitizer $sanitizer): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Only a dry run is available: re-run with --dry-run. Redacting stored AI history needs separate approval.');

            return self::FAILURE;
        }

        $categories = [];
        $conversations = [];
        $affected = ['messages' => 0, 'tool_calls' => 0, 'tool_results' => 0, 'action_logs' => 0];
        $review = [];
        $count = function (array $found, string $bucket, ?int $conversationId, string $reference) use (&$categories, &$conversations, &$affected, &$review): void {
            if ($found === []) {
                return;
            }

            $categories = AiPayloadSanitizer::merge($categories, $found);
            $affected[$bucket]++;
            $review[] = $reference;

            if ($conversationId !== null) {
                $conversations[$conversationId] = true;
            }
        };

        AiMessage::query()->lazyById(500)->each(function (AiMessage $message) use ($sanitizer, $count): void {
            $content = (string) $message->content;
            $found = $message->role === AiMessageRole::Tool ? $sanitizer->sanitizeJson($content)['counts'] : $sanitizer->sanitizeText($content)['counts'];
            $count($found, 'messages', $message->conversation_id, "ai_messages#{$message->id}");
        });

        AiToolCall::query()->with('message:id,conversation_id')->lazyById(500)->each(function (AiToolCall $call) use ($sanitizer, $count): void {
            $count($sanitizer->sanitizeStrings($call->arguments ?? [])['counts'], 'tool_calls', $call->message?->conversation_id, "ai_tool_calls#{$call->id}");
        });

        AiToolResult::query()->with('toolCall.message:id,conversation_id')->lazyById(500)->each(function (AiToolResult $result) use ($sanitizer, $count): void {
            $count($sanitizer->sanitize((array) $result->output)['counts'], 'tool_results', $result->toolCall?->message?->conversation_id, "ai_tool_results#{$result->id}");
        });

        AiActionLog::query()->lazyById(500)->each(function (AiActionLog $log) use ($sanitizer, $count): void {
            $found = AiPayloadSanitizer::merge($sanitizer->sanitize((array) $log->input)['counts'], $sanitizer->sanitize((array) $log->output)['counts']);
            $count($found, 'action_logs', $log->conversation_id, "ai_action_logs#{$log->id}");
        });

        $this->info('AI history privacy report (dry run — nothing was changed).');
        $this->table(['Measure', 'Count'], [
            ['Conversations affected', count($conversations)],
            ['Legacy conversations (before Phase 8.1)', AiConversation::query()->whereNull('privacy_version')->count()],
            ['Messages affected', $affected['messages']],
            ['Tool calls affected', $affected['tool_calls']],
            ['Tool results affected', $affected['tool_results']],
            ['Action logs affected', $affected['action_logs']],
        ]);
        $this->table(['Category', 'Occurrences'], collect($categories)->map(fn (int $n, string $kind) => [$kind, $n])->values()->all());

        if ($review !== []) {
            $this->line('Records requiring review: '.implode(', ', array_slice($review, 0, (int) $this->option('limit'))).(count($review) > (int) $this->option('limit') ? ' …' : ''));
        }

        // A system-level entry (no single subject record); counts only, never values.
        AuditLog::record((new AiConversation)->forceFill(['id' => 0]), 'ai_history_redaction_dry_run', null, [
            'conversations_affected' => count($conversations),
            ...$affected,
            'categories' => $categories,
        ]);

        return self::SUCCESS;
    }
}
