<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Support;

/**
 * Smile&Pay's application-level `responseCode`, which is independent of HTTP
 * status.
 *
 * The gateway can and does return HTTP 200 with a non-"00" body — a decline is
 * a successful HTTP exchange that reports a failed payment. Treating HTTP 200
 * as success is the single most common way to build a payment integration that
 * ships goods for free, so every response passes through here.
 */
final class ResponseCode
{
    /** The only code that means "the request itself succeeded". */
    public const SUCCESS = '00';

    /**
     * Human-readable meanings for codes seen in the wild, keyed by code.
     *
     * ISO-8583 style codes are used across Zimbabwean switches, so these
     * follow that convention. Unknown codes fall through to the gateway's own
     * `responseMessage`, which is always preferred when present.
     *
     * @var array<string, string>
     */
    public const MEANINGS = [
        '00' => 'Approved / successful',
        '01' => 'Refer to card issuer',
        '03' => 'Invalid merchant',
        '05' => 'Do not honour — declined by issuer',
        '12' => 'Invalid transaction',
        '13' => 'Invalid amount',
        '14' => 'Invalid account or mobile number',
        '30' => 'Format error in the request',
        '51' => 'Insufficient funds',
        '54' => 'Expired card',
        '55' => 'Incorrect PIN or OTP',
        '57' => 'Transaction not permitted for this account',
        '58' => 'Transaction not permitted for this terminal',
        '61' => 'Exceeds withdrawal amount limit',
        '65' => 'Exceeds withdrawal frequency limit',
        '75' => 'Allowable number of PIN tries exceeded',
        '91' => 'Issuer or switch unavailable',
        '96' => 'System malfunction',
    ];

    /**
     * Codes where retrying the same payment could plausibly succeed later.
     *
     * Deliberately narrow: a declined card retried in a loop annoys the
     * customer and can trip issuer fraud rules.
     *
     * @var list<string>
     */
    public const RETRYABLE = ['91', '96'];

    /**
     * Normalise a code to its two-character form. "0" and 0 both mean "00".
     */
    public static function normalise(string|int|null $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = trim((string) $code);

        if ($code === '') {
            return null;
        }

        return strlen($code) === 1 ? '0'.$code : $code;
    }

    public static function isSuccess(string|int|null $code): bool
    {
        return self::normalise($code) === self::SUCCESS;
    }

    public static function isRetryable(string|int|null $code): bool
    {
        $code = self::normalise($code);

        return $code !== null && in_array($code, self::RETRYABLE, true);
    }

    /**
     * Best available description: the gateway's own message wins, then the
     * built-in table, then a generic fallback that still names the code.
     */
    public static function describe(string|int|null $code, ?string $gatewayMessage = null): string
    {
        if ($gatewayMessage !== null && trim($gatewayMessage) !== '') {
            return trim($gatewayMessage);
        }

        $code = self::normalise($code);

        if ($code === null) {
            return 'Smile&Pay returned no response code.';
        }

        return self::MEANINGS[$code] ?? sprintf('Smile&Pay returned response code %s.', $code);
    }
}
