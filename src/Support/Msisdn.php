<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Support;

use AaronKatema\SmilePay\Enums\PaymentMethod;
use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;
use JsonSerializable;
use Stringable;

/**
 * A validated Zimbabwean mobile number.
 *
 * Customers type numbers every way imaginable — "0771 234 567", "+263 77 123
 * 4567", "263771234567". Normalising once at the boundary means the gateway,
 * the transaction log and any later reconciliation all see the same string,
 * which is what makes "find this customer's payments" a reliable query.
 *
 * Canonical form is MSISDN without a plus: 263771234567.
 */
final readonly class Msisdn implements JsonSerializable, Stringable
{
    /** Zimbabwe's E.164 country calling code. */
    public const COUNTRY_CODE = '263';

    /**
     * Mobile network prefixes in national format, mapped to their operator.
     *
     * @var array<string, string>
     */
    public const NETWORKS = [
        '71' => 'NetOne',
        '73' => 'Telecel',
        '77' => 'Econet',
        '78' => 'Econet',
    ];

    private function __construct(
        /** Canonical E.164 digits without the leading plus, e.g. 263771234567. */
        public string $value,
    ) {}

    /**
     * Parse and validate loose user input.
     *
     * @throws InvalidPaymentRequestException when the number is not a valid
     *                                        Zimbabwean mobile number.
     */
    public static function parse(self|string $input): self
    {
        if ($input instanceof self) {
            return $input;
        }

        $original = $input;
        $digits = (string) preg_replace('/\D+/', '', $input);

        if ($digits === '') {
            throw InvalidPaymentRequestException::invalidMsisdn($original);
        }

        // 00263... international prefix
        if (str_starts_with($digits, '00'.self::COUNTRY_CODE)) {
            $digits = substr($digits, 2);
        }

        $national = match (true) {
            // 263771234567 -> 771234567
            str_starts_with($digits, self::COUNTRY_CODE) => substr($digits, strlen(self::COUNTRY_CODE)),
            // 0771234567 -> 771234567
            str_starts_with($digits, '0') => substr($digits, 1),
            // 771234567, already national
            default => $digits,
        };

        if (! self::isValidNational($national)) {
            throw InvalidPaymentRequestException::invalidMsisdn($original);
        }

        return new self(self::COUNTRY_CODE.$national);
    }

    /**
     * Non-throwing variant for optional fields and validation rules.
     */
    public static function tryParse(self|string|null $input): ?self
    {
        if ($input === null || (is_string($input) && trim($input) === '')) {
            return null;
        }

        try {
            return self::parse($input);
        } catch (InvalidPaymentRequestException) {
            return null;
        }
    }

    public static function isValid(string $input): bool
    {
        return self::tryParse($input) instanceof self;
    }

    /**
     * A valid national number is 9 digits with a known mobile prefix.
     */
    private static function isValidNational(string $national): bool
    {
        if (strlen($national) !== 9) {
            return false;
        }

        return array_key_exists(substr($national, 0, 2), self::NETWORKS);
    }

    /** National format with the trunk zero: 0771234567. */
    public function national(): string
    {
        return '0'.substr($this->value, strlen(self::COUNTRY_CODE));
    }

    /** Full E.164 with the plus: +263771234567. */
    public function e164(): string
    {
        return '+'.$this->value;
    }

    /** Digits only, no plus: 263771234567. */
    public function international(): string
    {
        return $this->value;
    }

    /** Operator name, e.g. "Econet". */
    public function network(): string
    {
        $prefix = substr($this->value, strlen(self::COUNTRY_CODE), 2);

        return self::NETWORKS[$prefix] ?? 'Unknown';
    }

    /**
     * Whether this number's operator matches the chosen wallet.
     *
     * Sending an Econet number to a OneMoney request is a guaranteed decline,
     * so callers can warn the customer before spending a gateway call.
     *
     * Rails whose operator this package does not claim to know return true —
     * see PaymentMethod::network(). A false warning about a valid payment is
     * worse than no warning at all.
     */
    public function matchesMethod(PaymentMethod $method): bool
    {
        $expected = $method->network();

        return $expected === null || $this->network() === $expected;
    }

    /** Masked for logs and receipts: 26377****567. */
    public function masked(): string
    {
        return substr($this->value, 0, 5).'****'.substr($this->value, -3);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
