<?php

use App\Models\User;
use App\Services\NotificationDispatchService;

beforeEach(function (): void {
    $this->service = app(NotificationDispatchService::class);
});

test('alert sends a database notification to the recipient', function (): void {
    $user = User::factory()->create();

    $this->service->alert($user, 'Recruitment', 'Test alert', 'Something happened.', 'warning');

    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['title'])->toBe('[Recruitment] Test alert');
});

test('alert is a no-op when the recipient is null', function (): void {
    $this->service->alert(null, 'Recruitment', 'Test alert', 'Something happened.', 'warning');
})->throwsNoExceptions();

test('alert does not duplicate a notification with the same dedupe key', function (): void {
    $user = User::factory()->create();

    $this->service->alert($user, 'Recruitment', 'Test alert', 'Something happened.', 'warning', dedupeKey: 'unique-key');
    $this->service->alert($user, 'Recruitment', 'Test alert', 'Something happened again.', 'warning', dedupeKey: 'unique-key');

    expect($user->notifications()->count())->toBe(1);
});

test('alert sends separate notifications for different dedupe keys', function (): void {
    $user = User::factory()->create();

    $this->service->alert($user, 'Recruitment', 'Test alert', 'First.', 'warning', dedupeKey: 'key-one');
    $this->service->alert($user, 'Recruitment', 'Test alert', 'Second.', 'warning', dedupeKey: 'key-two');

    expect($user->notifications()->count())->toBe(2);
});
