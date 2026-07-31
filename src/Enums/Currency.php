<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Enums;

use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;

/**
 * Currencies settled by ZB Bank's Smile&Pay gateway.
 *
 * The API transmits currency as an ISO 4217 *numeric* code ("840", "924"), not
 * the alphabetic one. This enum keeps the readable alphabetic code as its value
 * — that is what appears in application code, database rows and log lines — and
 * converts to the numeric code only at the wire boundary.
 *
 * `minorUnits` drives all money maths. Amounts are held as integers internally
 * to avoid float rounding, then rendered to a decimal at the edge.
 */
enum Currency: string
{
    /** United States Dollar — ISO numeric 840. Dominant in Zimbabwe. */
    case USD = 'USD';

    /** Zimbabwe Gold — ISO numeric 924. Local currency, introduced April 2024. */
    case ZWG = 'ZWG';

    /**
     * The ISO 4217 numeric code sent to Smile&Pay as `currencyCode`.
     */
    public function numericCode(): string
    {
        return match ($this) {
            self::USD => '840',
            self::ZWG => '924',
        };
    }

    /**
     * Number of decimal places used when rendering this currency.
     */
    public function decimals(): int
    {
        return 2;
    }

    /**
     * Number of minor units (cents) in one major unit.
     */
    public function minorUnits(): int
    {
        return 10 ** $this->decimals();
    }

    public function symbol(): string
    {
        return match ($this) {
            self::USD => '$',
            self::ZWG => 'ZiG',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::USD => 'US Dollar',
            self::ZWG => 'Zimbabwe Gold',
        };
    }

    /**
     * Resolve from either notation: "USD", "usd", "840", or an existing case.
     *
     * Callbacks carry both `currency` ("USD") and `currencyCode` ("840"), and
     * this accepts whichever the caller happens to be holding.
     */
    public static function fromLoose(self|string|int $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $value = strtoupper(trim((string) $value));

        foreach (self::cases() as $case) {
            if ($case->value === $value || $case->numericCode() === $value) {
                return $case;
            }
        }

        throw InvalidPaymentRequestException::unsupportedCurrency($value);
    }

    /**
     * Non-throwing variant, for parsing gateway payloads that may drift.
     */
    public static function tryFromLoose(self|string|int|null $value): ?self
    {
        if ($value === null) {
            return null;
        }

        try {
            return self::fromLoose($value);
        } catch (InvalidPaymentRequestException) {
            return null;
        }
    }
}
