<?php

namespace App\Services\Billing;

use InvalidArgumentException;

/**
 * SaaS-4: an amount of money — an integer of minor units (paise, cents) in one currency. Never a
 * float: decimal input is parsed digit by digit, so "0.29" is exactly 29. Amounts are never
 * negative and never mixed across currencies (nothing is converted). The ceiling keeps every
 * stored amount, and any sum the billing code forms, far inside a signed 64-bit integer.
 */
final readonly class Money
{
    /**
     * 10^15 minor units: 10 trillion in a two-decimal currency.
     */
    public const int MAX_MINOR = 1_000_000_000_000_000;

    private function __construct(public int $minor, public string $currency)
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('An amount of money is never negative.');
        }

        if ($minor > self::MAX_MINOR) {
            throw new InvalidArgumentException('The amount is larger than billing supports.');
        }
    }

    public static function of(int $minor, string $currency): self
    {
        return new self($minor, self::currency($currency));
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    /**
     * Exact parse of a decimal string ("1234.5", "0.29"): no more fraction digits than the
     * currency has, no sign, no exponent, no thousands separators.
     */
    public static function parse(string $amount, string $currency): self
    {
        $currency = self::currency($currency);
        $precision = self::precision($currency);
        $amount = trim($amount);

        if (preg_match('/^(\d{1,16})(?:\.(\d+))?$/', $amount, $parts) !== 1) {
            throw new InvalidArgumentException("\"{$amount}\" is not an amount of money.");
        }

        $fraction = $parts[2] ?? '';

        if (strlen($fraction) > $precision) {
            throw new InvalidArgumentException("{$currency} has {$precision} decimal places; \"{$amount}\" has more.");
        }

        $minor = ltrim($parts[1].str_pad($fraction, $precision, '0'), '0');

        if (strlen($minor) > 16 || ($minor !== '' && (int) $minor > self::MAX_MINOR)) {
            throw new InvalidArgumentException('The amount is larger than billing supports.');
        }

        return new self($minor === '' ? 0 : (int) $minor, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->minor > $this->minor) {
            throw new InvalidArgumentException('The result would be negative.');
        }

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    /**
     * "1234.50" — the exact decimal, for display and provider requests.
     */
    public function decimal(): string
    {
        $precision = self::precision($this->currency);

        if ($precision === 0) {
            return (string) $this->minor;
        }

        $digits = str_pad((string) $this->minor, $precision + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$precision).'.'.substr($digits, -$precision);
    }

    /**
     * "INR 1,234.50".
     */
    public function format(): string
    {
        $decimal = $this->decimal();
        [$whole, $fraction] = str_contains($decimal, '.') ? explode('.', $decimal) : [$decimal, null];
        $whole = strrev(implode(',', str_split(strrev($whole), 3)));

        return $this->currency.' '.$whole.($fraction !== null ? '.'.$fraction : '');
    }

    public static function precision(string $currency): int
    {
        return (int) config('billing.currencies')[self::currency($currency)];
    }

    /**
     * @return list<string>
     */
    public static function currencies(): array
    {
        return array_keys((array) config('billing.currencies'));
    }

    private static function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (! array_key_exists($currency, (array) config('billing.currencies'))) {
            throw new InvalidArgumentException("Billing does not offer the currency \"{$currency}\".");
        }

        return $currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency}: amounts are never converted.");
        }
    }
}
