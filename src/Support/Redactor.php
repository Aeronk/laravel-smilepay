<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Support;

/**
 * Strips secrets and personal data out of anything on its way to a log.
 *
 * Payment logs are kept for years, shipped to third-party aggregators and read
 * by support staff who have no business seeing an API secret or a full customer
 * phone number. Redacting at the point of logging — rather than trusting every
 * future call site to remember — is the only version of this that holds up.
 */
final class Redactor
{
    /**
     * Keys whose values are replaced entirely. Matched case-insensitively and
     * ignoring separators, so `api_secret`, `apiSecret` and `X-API-SECRET` all
     * hit.
     *
     * @var list<string>
     */
    public const SECRET_KEYS = [
        'apikey', 'apisecret', 'xapikey', 'xapisecret',
        'secret', 'password', 'pin', 'otp', 'token',
        'authorization', 'accesstoken', 'refreshtoken',
        'cardnumber', 'pan', 'cvv', 'cvc', 'expiry', 'expirydate',
    ];

    /**
     * Keys that are partially masked — enough survives to correlate a support
     * ticket, not enough to contact the customer.
     *
     * @var list<string>
     */
    public const MASKED_KEYS = [
        'mobilephonenumber', 'mobilenumber', 'msisdn', 'phone', 'mobile', 'email',
    ];

    public const REDACTED = '[redacted]';

    /**
     * Recursively scrub an array.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrub(array $data, int $depth = 0): array
    {
        // Guards against a pathological or hostile payload nesting deeply
        // enough to exhaust the stack inside a log call.
        if ($depth > 12) {
            return ['_truncated' => true];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            $normalised = is_string($key) ? self::normalise($key) : '';

            if ($normalised !== '' && in_array($normalised, self::SECRET_KEYS, true)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::scrub($value, $depth + 1);

                continue;
            }

            if ($normalised !== '' && in_array($normalised, self::MASKED_KEYS, true) && is_string($value)) {
                $clean[$key] = str_contains($value, '@')
                    ? self::maskEmail($value)
                    : self::maskDigits($value);

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Mask a credential for a one-line diagnostic, e.g. "sk_l****9f2c".
     */
    public static function maskCredential(?string $value): string
    {
        if ($value === null || $value === '') {
            return self::REDACTED;
        }

        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4).'****'.substr($value, -4);
    }

    private static function maskDigits(string $value): string
    {
        if (strlen($value) <= 6) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4).str_repeat('*', max(strlen($value) - 7, 1)).substr($value, -3);
    }

    private static function maskEmail(string $value): string
    {
        [$local, $domain] = array_pad(explode('@', $value, 2), 2, '');

        if ($domain === '') {
            return self::maskDigits($value);
        }

        return mb_substr($local, 0, 1)
            .str_repeat('*', max(mb_strlen($local) - 1, 1))
            .'@'.$domain;
    }

    private static function normalise(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }
}
