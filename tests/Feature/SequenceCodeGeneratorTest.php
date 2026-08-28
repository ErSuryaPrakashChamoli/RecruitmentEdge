<?php

use App\Services\SequenceCodeGenerator;

test('generates sequential, zero-padded codes per prefix and year', function (): void {
    $generator = app(SequenceCodeGenerator::class);

    $year = now()->year;

    expect($generator->next('REQ'))->toBe("REQ-{$year}-000001")
        ->and($generator->next('REQ'))->toBe("REQ-{$year}-000002")
        ->and($generator->next('REQ'))->toBe("REQ-{$year}-000003");
});

test('different prefixes have independent sequences', function (): void {
    $generator = app(SequenceCodeGenerator::class);

    $year = now()->year;

    expect($generator->next('CAND'))->toBe("CAND-{$year}-000001")
        ->and($generator->next('REQ'))->toBe("REQ-{$year}-000001")
        ->and($generator->next('CAND'))->toBe("CAND-{$year}-000002");
});
