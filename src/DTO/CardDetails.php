<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\DTO;

use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;
use SensitiveParameter;

/**
 * Raw card data for the MPGS express-checkout endpoint.
 *
 * ## Read this before you use this class
 *
 * Passing a raw PAN through your own server puts that server **in PCI-DSS
 * scope** — SAQ D rather than SAQ A. In practice that means quarterly ASV
 * scans, penetration testing, network segmentation and an annual audit, and it
 * applies to every machine the data touches: web servers, queue workers, log
 * aggregators, database replicas, backups.
 *
 * The hosted Standard Checkout flow (`SmilePay::checkout()`) keeps card data
 * entirely on ZB's infrastructure and leaves you on SAQ A. Unless you have a
 * concrete commercial reason and a compliance programme to match, use that.
 *
 * Note also that ZB's own documentation demonstrates this endpoint being called
 * from browser JavaScript with the API key and secret embedded in the page.
 * **Do not do that.** Anyone who opens devtools gets your merchant credentials
 * and can initiate payments as you. This class exists to be used server-side.
 *
 * The object defends what it can: it never appears in `var_dump`, print_r,
 * `json_encode`, exception traces or logs, and the CVV is held only for the
 * lifetime of the request.
 */
final readonly class CardDetails
{
    private function __construct(
        public string $pan,
        public string $expMonth,
        public string $expYear,
        public string $securityCode,
    ) {}

    /**
     * @param  string  $pan  Primary account number, spaces and dashes tolerated.
     * @param  string  $expMonth  "01".."12".
     * @param  string  $expYear  Two or four digits; normalised to two.
     * @param  string  $securityCode  CVV/CVC, 3 or 4 digits.
     * @param  bool  $strictLuhn  Enforce the mod-10 checksum.
     *
     * Leave `$strictLuhn` on in production — it catches customer typos before
     * they cost a gateway call and a decline on the card's record.
     *
     * Turn it off only for ZB's sandbox: their "system error" (5123450000000002)
     * and "declined" (5123450000000010) test cards do not satisfy Luhn, so the
     * failure paths cannot be exercised with the check enabled. Whether that is
     * deliberate on ZB's part or a documentation typo is worth confirming with
     * your integration contact.
     */
    public static function make(
        #[SensitiveParameter] string $pan,
        string $expMonth,
        string $expYear,
        #[SensitiveParameter] string $securityCode,
        bool $strictLuhn = true,
    ): self {
        $pan = (string) preg_replace('/\D+/', '', $pan);

        if (strlen($pan) < 12 || strlen($pan) > 19) {
            throw InvalidPaymentRequestException::invalidCard('card number must be 12 to 19 digits');
        }

        if ($strictLuhn && ! self::passesLuhn($pan)) {
            throw InvalidPaymentRequestException::invalidCard('card number failed the Luhn check');
        }

        $month = str_pad((string) preg_replace('/\D+/', '', $expMonth), 2, '0', STR_PAD_LEFT);

        if ((int) $month < 1 || (int) $month > 12) {
            throw InvalidPaymentRequestException::invalidCard('expiry month must be between 01 and 12');
        }

        $year = (string) preg_replace('/\D+/', '', $expYear);

        // The API takes a two-digit year; accept four and narrow it.
        if (strlen($year) === 4) {
            $year = substr($year, 2);
        }

        if (strlen($year) !== 2) {
            throw InvalidPaymentRequestException::invalidCard('expiry year must be two or four digits');
        }

        $cvv = (string) preg_replace('/\D+/', '', $securityCode);

        if (strlen($cvv) < 3 || strlen($cvv) > 4) {
            throw InvalidPaymentRequestException::invalidCard('security code must be 3 or 4 digits');
        }

        return new self($pan, $month, $year, $cvv);
    }

    /**
     * Parse an "MM/YY" or "MM/YYYY" expiry alongside the other fields.
     */
    public static function fromExpiryString(
        #[SensitiveParameter] string $pan,
        string $expiry,
        #[SensitiveParameter] string $securityCode,
        bool $strictLuhn = true,
    ): self {
        $parts = preg_split('#[/\-\s]+#', trim($expiry)) ?: [];

        if (count($parts) !== 2) {
            throw InvalidPaymentRequestException::invalidCard('expiry must be in MM/YY or MM/YYYY format');
        }

        return self::make($pan, $parts[0], $parts[1], $securityCode, $strictLuhn);
    }

    /**
     * Whether the card is expired as at the given month.
     *
     * Checked locally so an obviously dead card never becomes a gateway call
     * and a decline on the customer's record.
     */
    public function isExpired(?int $nowYear = null, ?int $nowMonth = null): bool
    {
        $nowYear ??= (int) date('y');
        $nowMonth ??= (int) date('n');

        $year = (int) $this->expYear;
        $month = (int) $this->expMonth;

        return $year < $nowYear || ($year === $nowYear && $month < $nowMonth);
    }

    /**
     * Last four digits — the only part safe to store or display.
     */
    public function last4(): string
    {
        return substr($this->pan, -4);
    }

    /**
     * Card brand inferred from the IIN range.
     */
    public function brand(): string
    {
        return match (true) {
            (bool) preg_match('/^4/', $this->pan) => 'Visa',
            (bool) preg_match('/^(5[1-5]|2[2-7])/', $this->pan) => 'Mastercard',
            (bool) preg_match('/^3[47]/', $this->pan) => 'American Express',
            default => 'Unknown',
        };
    }

    /**
     * Safe descriptor for the transaction log, e.g. "Visa ****0008".
     */
    public function describe(): string
    {
        return sprintf('%s ****%s', $this->brand(), $this->last4());
    }

    /**
     * Field names exactly as the MPGS endpoint expects them.
     *
     * The only method that ever exposes the PAN. Kept deliberately narrow so
     * that auditing "where can card data leave this package" is one grep.
     *
     * @return array<string, string>
     */
    public function toGatewayPayload(): array
    {
        return [
            'pan' => $this->pan,
            'expMonth' => $this->expMonth,
            'expYear' => $this->expYear,
            'securityCode' => $this->securityCode,
        ];
    }

    /**
     * Neutralises `var_dump()` and Laravel's `dd()`.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['card' => $this->describe(), 'expiry' => '**/**', 'securityCode' => '***'];
    }

    /**
     * Neutralises accidental `json_encode($request)` on a request holding a card.
     */
    public function __serialize(): array
    {
        return ['card' => $this->describe()];
    }

    /**
     * Refuses to be unserialised — a serialised card in a queue payload, a
     * session or a cache entry is exactly the leak this class exists to prevent.
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw InvalidPaymentRequestException::invalidCard(
            'card details cannot be unserialised. Never queue, cache or session-store raw card data.'
        );
    }

    /**
     * Standard mod-10 checksum. Catches typos before they cost a gateway call.
     */
    private static function passesLuhn(string $pan): bool
    {
        if (strlen($pan) < 12 || strlen($pan) > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = strlen($pan) - 1; $i >= 0; $i--) {
            $digit = (int) $pan[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
