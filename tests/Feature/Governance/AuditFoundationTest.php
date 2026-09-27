<?php

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AiKnowledgeArticle;
use App\Models\AuditLog;
use App\Models\CalendarConnection;
use App\Models\Candidate;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentManualActivity;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.6 audit foundation (D8.6-003/024/025): a reason travels with a change, restore and
 * permanent delete are their own actions, hidden values are marked but never copied, explicit
 * audit payloads are redacted like model diffs, and the remaining deletable operational records
 * are audited.
 */
function auditRowsFor(object $model, ?string $action = null): Collection
{
    return AuditLog::query()
        ->where('auditable_type', $model::class)
        ->where('auditable_id', $model->getKey())
        ->when($action, fn ($query) => $query->where('action', $action))
        ->orderBy('id')
        ->get();
}

test('a reason given with withReason is stored on every audit row the change writes', function (): void {
    $candidate = Candidate::factory()->create(['full_name' => 'Before']);

    AuditLog::withReason('Name corrected from passport', fn () => $candidate->update(['full_name' => 'After']));
    $candidate->update(['full_name' => 'Later']);

    $updates = auditRowsFor($candidate, 'updated');

    expect($updates)->toHaveCount(2)
        ->and($updates[0]->reason)->toBe('Name corrected from passport')
        ->and($updates[1]->reason)->toBeNull();
});

test('an explicit reason on record wins over the surrounding one and nesting restores it', function (): void {
    $candidate = Candidate::factory()->create();

    AuditLog::withReason('outer', function () use ($candidate): void {
        AuditLog::record($candidate, 'custom_a', null, null, 'explicit');
        AuditLog::withReason('inner', fn () => AuditLog::record($candidate, 'custom_b', null, null));
        AuditLog::record($candidate, 'custom_c', null, null);
    });

    expect(auditRowsFor($candidate)->whereIn('action', ['custom_a', 'custom_b', 'custom_c'])->pluck('reason', 'action')->all())
        ->toBe(['custom_a' => 'explicit', 'custom_b' => 'inner', 'custom_c' => 'outer']);
});

test('restoring a soft-deleted model is one restored row and a permanent delete is force_deleted', function (): void {
    $candidate = Candidate::factory()->create();

    $candidate->delete();
    $candidate->restore();
    $candidate->forceDelete();

    $actions = auditRowsFor($candidate)->pluck('action')->all();

    expect($actions)->toBe(['created', 'deleted', 'restored', 'force_deleted']);
});

test('a hidden attribute change is recorded as changed without its value', function (): void {
    $connection = CalendarConnection::factory()->create(['access_token' => 'first-secret-token']);

    $connection->update(['access_token' => 'second-secret-token']);

    $row = auditRowsFor($connection, 'updated')->sole();
    $raw = json_encode([$row->changes, $row->old_values]);

    expect($row->changes['access_token'])->toBe('[changed]')
        ->and($row->old_values['access_token'])->toBe('[hidden]')
        ->and(str_contains($raw, 'secret-token'))->toBeFalse('a hidden value reached the audit log');
});

test('an explicit audit record redacts the subject\'s redacted attributes', function (): void {
    $offer = lifecycleFixture(fn () => Offer::factory()->create());

    AuditLog::record($offer, 'custom_terms_note', ['offered_ctc' => 1000000], ['offered_ctc' => 1200000, 'status' => 'draft']);

    $row = auditRowsFor($offer, 'custom_terms_note')->sole();

    expect($row->old_values['offered_ctc'])->toBe('[redacted]')
        ->and($row->changes['offered_ctc'])->toBe('[redacted]')
        ->and($row->changes['status'])->toBe('draft');
});

test('follow-ups, manual activities and knowledge articles are audited with their free text redacted', function (): void {
    $followup = RecruitmentFollowup::factory()->create(['remarks' => 'candidate private note']);
    $activity = RecruitmentManualActivity::factory()->create(['remarks' => 'field visit detail']);
    $article = AiKnowledgeArticle::factory()->create(['content' => 'internal guidance body']);

    $followup->update(['remarks' => 'edited private note']);
    $activity->delete();
    $article->update(['content' => 'new guidance body']);

    expect(auditRowsFor($followup)->pluck('action')->all())->toBe(['created', 'updated'])
        ->and(auditRowsFor($activity)->pluck('action')->all())->toBe(['created', 'deleted'])
        ->and(auditRowsFor($article)->pluck('action')->all())->toBe(['created', 'updated']);

    $text = AuditLog::query()->pluck('changes')->merge(AuditLog::query()->pluck('old_values'))->toJson();

    expect(str_contains($text, 'private note'))->toBeFalse()
        ->and(str_contains($text, 'field visit detail'))->toBeFalse()
        ->and(str_contains($text, 'guidance body'))->toBeFalse();
});

test('the audit log can be filtered by date, actor and request id', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $viewer = User::factory()->create()->assignRole('vp_hr');
    $other = User::factory()->create();
    actingAs($viewer);

    $candidate = Candidate::factory()->create();
    Context::add('request_id', 'req-governance-1');
    $mine = AuditLog::record($candidate, 'custom_mine', null, null);
    Context::forget('request_id');
    $old = AuditLog::record($candidate, 'custom_old', null, null);
    $old->forceFill(['created_at' => now()->subDays(40)])->save();
    actingAs($other);
    $theirs = AuditLog::record($candidate, 'custom_theirs', null, null);
    actingAs($viewer);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('request_id', ['request_id' => 'req-governance-1'])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$old, $theirs]);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('user_id', $other->id)
        ->assertCanSeeTableRecords([$theirs])
        ->assertCanNotSeeTableRecords([$mine]);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('created_at', ['from' => now()->subDays(45)->toDateString(), 'until' => now()->subDays(35)->toDateString()])
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$mine, $theirs]);
});
