<?php

use App\Services\AI\Privacy\AiFieldPolicy;
use App\Services\AI\Privacy\AiPayloadSanitizer;
use App\Services\AI\Privacy\AiReference;
use App\Services\AI\Privacy\AiSensitiveValues;
use App\Services\AI\Privacy\PiiPatternScrubber;

test('the scrubber removes emails, phone numbers, PAN, Aadhaar and currency amounts', function (string $input, string $kind): void {
    $result = (new PiiPatternScrubber)->scrub("Details: {$input}.");

    expect($result['text'])->not->toContain($input)
        ->and($result['counts'])->toHaveKey($kind);
})->with([
    'email' => ['PRIVATE-ALICE@example.invalid', 'email'],
    'mobile' => ['9999912345', 'phone'],
    'mobile with country code' => ['+91 99999 12345', 'phone'],
    'PAN' => ['ABCDE1234F', 'pan'],
    'Aadhaar' => ['2345 6789 0123', 'aadhaar'],
    'rupee amount' => ['₹15,00,000', 'amount'],
    'LPA' => ['18 LPA', 'amount'],
]);

test('the scrubber leaves AI references, dates and ordinary numbers alone', function (): void {
    $text = 'CAND-2026-908995 applied for REQ-2026-166683 on 2026-09-26 (APP-2026-035253, EMP-000014, INT-42); 3 rounds, score 4.5.';

    expect((new PiiPatternScrubber)->scrub($text))->toBe(['text' => $text, 'counts' => []]);
});

test('registered personal values are removed wherever they appear, case-insensitively and as whole words', function (): void {
    $values = new AiSensitiveValues;
    $values->add('PRIVATE-CANDIDATE-ALICE');
    $values->add('Bo');

    $result = $values->scrub('Summary: private-candidate-alice was strong. Bob is fine.');

    expect($result['text'])->toBe('Summary: [name removed] was strong. Bob is fine.')
        ->and($result['counts'])->toBe(['name' => 1]);
});

test('the sanitizer drops prohibited keys at any depth and scrubs every string', function (): void {
    $values = new AiSensitiveValues;
    $values->add('PRIVATE-CANDIDATE-ALICE');
    $sanitizer = new AiPayloadSanitizer(new PiiPatternScrubber, $values);

    $result = $sanitizer->sanitize([
        'candidate_ref' => 'CAND-2026-000001',
        'email' => 'PRIVATE-ALICE@example.invalid',
        'nested' => ['expected_salary' => 99999999, 'recruiter_mobile' => '9999912345', 'note' => 'Call PRIVATE-CANDIDATE-ALICE on 9999912345'],
        'list' => [['remarks' => 'secret', 'stage' => 'Screened']],
    ]);

    expect($result['payload'])->toBe([
        'candidate_ref' => 'CAND-2026-000001',
        'nested' => ['note' => 'Call [name removed] on [phone removed]'],
        'list' => [['stage' => 'Screened']],
    ])->and($result['counts'])->toBe(['field' => 4, 'name' => 1, 'phone' => 1]);
});

test('model-written arguments keep their keys but are still scrubbed', function (): void {
    $sanitizer = new AiPayloadSanitizer(new PiiPatternScrubber, new AiSensitiveValues);

    expect($sanitizer->sanitizeStrings(['remarks' => 'Reach them at x@y.test', 'application_ids' => [1, 2]])['payload'])
        ->toBe(['remarks' => 'Reach them at [email removed]', 'application_ids' => [1, 2]]);
});

test('field policy recognises prohibited keys and suffixes but not ordinary ones', function (string $key, bool $prohibited): void {
    expect(AiFieldPolicy::isProhibitedKey($key))->toBe($prohibited);
})->with([
    ['full_name', true], ['Email', true], ['manager_email', true], ['expected_ctc', true], ['offered_ctc', true],
    ['remarks', true], ['candidate_ref', false], ['designation', false], ['stage', false], ['name', false],
]);

test('the reference pattern matches every reference format', function (): void {
    preg_match_all(AiReference::PATTERN, 'CAND-2026-000123 APP-2026-000456 EMP-000789 REQ-2026-000321 OFR-2026-000001 INT-11 JOIN-5 FUP-7 RISK-9 CAND-12', $matches);

    expect($matches[0])->toBe(['CAND-2026-000123', 'APP-2026-000456', 'EMP-000789', 'REQ-2026-000321', 'OFR-2026-000001', 'INT-11', 'JOIN-5', 'FUP-7', 'RISK-9']);
});
