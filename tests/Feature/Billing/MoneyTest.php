<?php

use App\Services\Billing\Money;

/*
 * SaaS-4: money is an integer of minor units in one currency — exact, never negative, never mixed.
 */
test('decimal amounts parse exactly, digit by digit — never through a float', function (string $input, int $minor): void {
    expect(Money::parse($input, 'INR')->minor)->toBe($minor);
})->with([
    'zero' => ['0', 0],
    'zero with decimals' => ['0.00', 0],
    'whole' => ['4999', 499900],
    'one decimal' => ['1234.5', 123450],
    'a float trap' => ['0.29', 29],
    'another float trap' => ['1.15', 115],
    'large' => ['9999999999999.99', 999999999999999],
]);

test('anything that is not an exact amount of the currency is refused', function (string $input): void {
    expect(fn () => Money::parse($input, 'INR'))->toThrow(InvalidArgumentException::class);
})->with(['-5.00', '1.234', '1e3', '1,000.00', 'abc', '', ' . ', '10000000000000.01', '99999999999999999']);

test('a currency\'s own precision applies, and unknown currencies are refused', function (): void {
    config(['billing.currencies' => ['INR' => 2, 'JPY' => 0]]);

    expect(Money::parse('500', 'JPY')->minor)->toBe(500)
        ->and(fn () => Money::parse('500.5', 'JPY'))->toThrow(InvalidArgumentException::class, 'decimal places')
        ->and(fn () => Money::of(100, 'XYZ'))->toThrow(InvalidArgumentException::class, 'does not offer')
        ->and(Money::of(500, 'JPY')->decimal())->toBe('500');
});

test('arithmetic stays in one currency, never goes negative, never overflows', function (): void {
    $price = Money::parse('4999.00', 'INR');

    expect($price->plus(Money::parse('0.01', 'INR'))->minor)->toBe(499901)
        ->and($price->minus($price)->isZero())->toBeTrue()
        ->and(fn () => Money::zero('INR')->minus($price))->toThrow(InvalidArgumentException::class, 'negative')
        ->and(fn () => $price->plus(Money::of(100, 'USD')))->toThrow(InvalidArgumentException::class, 'never converted')
        ->and(fn () => Money::of(Money::MAX_MINOR, 'INR')->plus(Money::of(1, 'INR')))->toThrow(InvalidArgumentException::class, 'larger')
        ->and(fn () => Money::of(-1, 'INR'))->toThrow(InvalidArgumentException::class);
});

test('display keeps the exact amount and its currency', function (): void {
    expect(Money::of(123456789, 'INR')->format())->toBe('INR 1,234,567.89')
        ->and(Money::of(5, 'USD')->decimal())->toBe('0.05')
        ->and(Money::zero('INR')->format())->toBe('INR 0.00');
});
