<?php

use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\User;

test('creating an audited model writes a created audit log', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $candidate = Candidate::factory()->create();

    $log = AuditLog::query()->where('auditable_type', Candidate::class)->where('auditable_id', $candidate->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('created')
        ->and($log->user_id)->toBe($user->id);
});

test('updating an audited model writes an updated audit log excluding noise fields', function (): void {
    $candidate = Candidate::factory()->create(['full_name' => 'Original Name']);

    $candidate->update(['full_name' => 'New Name']);

    $log = AuditLog::query()
        ->where('auditable_type', Candidate::class)
        ->where('auditable_id', $candidate->id)
        ->where('action', 'updated')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->changes)->toHaveKey('full_name')
        ->and($log->changes)->not->toHaveKey('updated_at');
});

test('a no-op update does not write an audit log', function (): void {
    $candidate = Candidate::factory()->create();

    AuditLog::query()->where('auditable_type', Candidate::class)->where('auditable_id', $candidate->id)->delete();

    $candidate->update(['full_name' => $candidate->full_name]);

    expect(AuditLog::query()->where('auditable_type', Candidate::class)->where('auditable_id', $candidate->id)->exists())->toBeFalse();
});

test('deleting an audited model writes a deleted audit log', function (): void {
    $candidate = Candidate::factory()->create();

    $candidate->delete();

    $log = AuditLog::query()
        ->where('auditable_type', Candidate::class)
        ->where('auditable_id', $candidate->id)
        ->where('action', 'deleted')
        ->first();

    expect($log)->not->toBeNull();
});
