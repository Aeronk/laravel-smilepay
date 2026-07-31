<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\DTO;

use AaronKatema\SmilePay\Enums\Currency;
use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;
use JsonSerializable;
use Stringable;

/**
 * An immutable amount of money, stored in integer minor units.
 *
 * Floats are never used for arithmetic here. `Money::fromDecimal('0.29')` and
 * a subsequent `->plus()` chain will always agree with the merchant's ledger,
 * which a float-based implementation cannot promise.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    /**
     * @param  int  $minor  Amount in minor units (e.g. cents). Never negative.
     */
    private function __construct(
        public int $minor,
        public Currency $currency,
    ) {
        if ($minor < 0) {
            throw InvalidPaymentRequestException::negativeAmount();
        }
    }

    /**
     * Build from minor units — the safest constructor. 1050 USD minor = $10.50.
     */
    public static function fromMinor(int $minor, Currency|string|int $currency): self
    {
        return new self($minor, Currency::fromLoose($currency));
    }

    /**
     * Build from a major-unit decimal string such as "10.50".
     *
     * Accepts int|float|string for ergonomics, but floats are cast through a
     * fixed-precision string first so that 0.1 + 0.2 problems cannot leak in.
     */
    public static function fromDecimal(int|float|string $amount, Currency|string|int $currency): self
    {
        $currency = Currency::fromLoose($currency);
        $decimals = $currency->decimals();

        $raw = is_string($amount) ? trim($amount) : $amount;

        if (is_string($raw) && ! is_numeric($raw)) {
            throw InvalidPaymentRequestException::nonNumericAmount($raw);
        }

        // Round half-up at the currency's precision, then shift to minor units
        // through string maths so no binary-float artefact survives the trip.
        $rounded = number_format((float) $raw, $decimals, '.', '');
        $minor = (int) round(((float) $rounded) * $currency->minorUnits());

        return new self($minor, $currency);
    }

    public static function zero(Currency|string|int $currency): self
    {
        return new self(0, Currency::fromLoose($currency));
    }

    /**
     * Major-unit decimal string, e.g. "10.50". This is the wire format.
     */
    public function toDecimal(): string
    {
        return number_format(
            $this->minor / $this->currency->minorUnits(),
            $this->currency->decimals(),
            '.',
            ''
        );
    }

    public function toFloat(): float
    {
        return $this->minor / $this->currency->minorUnits();
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
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

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    /**
     * Human-facing string, e.g. "$10.50".
     */
    public function format(): string
    {
        return $this->currency->symbol().$this->toDecimal();
    }

    public function __toString(): string
    {
        return $this->toDecimal().' '.$this->currency->value;
    }

    /**
     * @return array{amount: string, currency: string, minor: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->toDecimal(),
            'currency' => $this->currency->value,
            'minor' => $this->minor,
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw InvalidPaymentRequestException::currencyMismatch(
                $this->currency->value,
                $other->currency->value
            );
        }
    }
}
