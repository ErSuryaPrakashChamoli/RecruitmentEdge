<?php

use PHPUnit\Framework\ExpectationFailedException;

/*
 * P810-RC-02: toBeJsonEquivalent() (tests/Pest.php) ignores only the order of JSON object keys —
 * every other difference still fails the assertion.
 */
test('JSON objects with the same keys and values in another order are equivalent, at every level', function (): void {
    expect(['b' => 2, 'a' => ['y' => [1, 2], 'x' => null]])->toBeJsonEquivalent(['a' => ['x' => null, 'y' => [1, 2]], 'b' => 2]);
});

test('a different value, type, key or list order is not equivalent', function (mixed $actual, mixed $expected): void {
    expect(fn () => expect($actual)->toBeJsonEquivalent($expected))->toThrow(ExpectationFailedException::class);
})->with([
    'a different value' => [['a' => 1], ['a' => 2]],
    'integer and string' => [['a' => 1], ['a' => '1']],
    'null and false' => [['a' => null], ['a' => false]],
    'an extra key' => [['a' => 1, 'b' => 2], ['a' => 1]],
    'a list in another order' => [['a' => [1, 2]], ['a' => [2, 1]]],
    'a nested difference' => [['a' => ['b' => ['c' => 1]]], ['a' => ['b' => ['c' => 0]]]],
]);
