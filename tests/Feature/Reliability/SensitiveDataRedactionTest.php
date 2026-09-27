<?php

use App\Logging\RedactingFailedJobProvider;
use App\Logging\SensitiveDataRedactor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 8.7 (SEC-87-07, D8.7-012): personal data and secrets are redacted centrally — in every log
 * channel and in the exception text kept in failed_jobs — and failed jobs are pruned after the
 * retention window.
 */
test('the redactor removes contact details, credentials and provider keys but keeps ordinary numbers', function (): void {
    $text = 'Twilio rejected +91 98765 43210 and 9876543210 for priya@example.com; Authorization: Bearer abc.def.ghi; '
        .'https://x.test/reset?token=RESET123&page=2 "api_key":"sk-live-abcdefghijklmnop" AIzaSyA1234567890abcdefghijk id=1717171717 order 12345678';

    $clean = SensitiveDataRedactor::text($text);

    foreach (['98765 43210', '9876543210', 'priya@example.com', 'abc.def.ghi', 'RESET123', 'sk-live-abcdefghijklmnop', 'AIzaSyA1234567890abcdefghijk'] as $secret) {
        expect(str_contains($clean, $secret))->toBeFalse("{$secret} survived redaction");
    }

    expect($clean)->toContain('1717171717')->toContain('12345678')->toContain('page=2');
});

test('context values under sensitive keys are replaced whole, recursively', function (): void {
    $context = SensitiveDataRedactor::context(['communication_id' => 7, 'recipient' => 'a@b.test', 'nested' => ['body' => 'Dear Priya', 'offered_ctc' => 1200000, 'note' => 'call 9876543210']]);

    expect($context)->toBe(['communication_id' => 7, 'recipient' => '[redacted]', 'nested' => ['body' => '[redacted]', 'offered_ctc' => '[redacted]', 'note' => 'call [redacted]']]);
});

test('every application log channel redacts before writing', function (): void {
    $path = storage_path('logs/redaction-test-'.Str::random(8).'.log');
    config(['logging.channels.single.path' => $path]);

    Log::channel('single')->error('Provider refused priya@example.com', ['recipient' => '9876543210', 'exception' => new RuntimeException('Invalid number +91 98765 43210')]);

    $written = (string) file_get_contents($path);
    @unlink($path);

    expect(str_contains($written, 'priya@example.com'))->toBeFalse()
        ->and(str_contains($written, '9876543210'))->toBeFalse()
        ->and(str_contains($written, '98765 43210'))->toBeFalse()
        ->and($written)->toContain('RuntimeException');
});

test('the exception text stored in failed_jobs is redacted', function (): void {
    $failer = app('queue.failer');

    $failer->log('database', 'communications', json_encode(['uuid' => (string) Str::uuid(), 'displayName' => 'App\\Jobs\\SendCommunicationJob']), new RuntimeException('Temporary provider failure for priya@example.com (+91 98765 43210)'));

    $stored = DB::table('failed_jobs')->value('exception');

    expect($failer)->toBeInstanceOf(RedactingFailedJobProvider::class)
        ->and(str_contains($stored, 'priya@example.com'))->toBeFalse()
        ->and(str_contains($stored, '98765 43210'))->toBeFalse()
        ->and($stored)->toContain('Temporary provider failure');
});

test('failed jobs older than the retention window are pruned daily; recent ones stay', function (): void {
    $insert = fn (int $hoursAgo) => DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subHours($hoursAgo)]);
    $insert(721);
    $insert(10);

    $this->artisan('queue:prune-failed', ['--hours' => config('queue.failed.retention_hours')])->assertSuccessful();

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'queue:prune-failed'));

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(config('queue.failed.retention_hours'))->toBe(720)
        ->and($event?->command)->toContain('--hours=720');
});
