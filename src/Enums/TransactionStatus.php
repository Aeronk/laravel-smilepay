<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Enums;

/**
 * Normalised lifecycle state of a Smile&Pay transaction.
 *
 * Gateways change their status vocabulary over time and rarely announce it.
 * This package therefore never leaks the raw gateway string into application
 * logic: raw values are mapped here through `smilepay.status_map`, and an
 * unrecognised value degrades to `UNKNOWN` rather than being guessed as PAID.
 * That asymmetry is deliberate — a false PAID releases goods for free.
 */
enum TransactionStatus: string
{
    /** Created locally, not yet acknowledged by the gateway. */
    case PENDING = 'pending';

    /** Accepted by the gateway; customer prompt issued, awaiting action. */
    case PROCESSING = 'processing';

    /** Card payment held at the 3-D Secure challenge, awaiting the issuer. */
    case AWAITING_3DS = 'awaiting_3ds';

    /** Two-step wallet: OTP sent by SMS, awaiting the customer's code. */
    case AWAITING_OTP = 'awaiting_otp';

    /** Customer authorised; funds not yet confirmed settled. */
    case AUTHORISED = 'authorised';

    /** Terminal success — funds confirmed. */
    case PAID = 'paid';

    /** Terminal failure — declined, insufficient funds, wrong PIN. */
    case FAILED = 'failed';

    /** Terminal — abandoned by the customer or cancelled by the merchant. */
    case CANCELLED = 'cancelled';

    /** Terminal — the customer never responded within the gateway window. */
    case EXPIRED = 'expired';

    /** Terminal — full value returned to the customer. */
    case REFUNDED = 'refunded';

    /** Part of the value returned; the remainder stays settled. */
    case PARTIALLY_REFUNDED = 'partially_refunded';

    /** Gateway returned a status this package does not recognise. */
    case UNKNOWN = 'unknown';

    /**
     * A final state will never change again, so polling can stop.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::PAID,
            self::FAILED,
            self::CANCELLED,
            self::EXPIRED,
            self::REFUNDED,
            self::PARTIALLY_REFUNDED => true,
            self::PENDING,
            self::PROCESSING,
            self::AWAITING_3DS,
            self::AWAITING_OTP,
            self::AUTHORISED,
            self::UNKNOWN => false,
        };
    }

    /**
     * Whether the customer still has something to do before funds can move.
     *
     * Drives the UI: a transaction awaiting an OTP needs an input box, one
     * awaiting 3DS needs the challenge iframe, and one merely PROCESSING needs
     * a spinner.
     */
    public function needsCustomerAction(): bool
    {
        return match ($this) {
            self::AWAITING_3DS, self::AWAITING_OTP => true,
            default => false,
        };
    }

    /**
     * True only when money is confirmed received. Never treat UNKNOWN as paid.
     */
    public function isSuccessful(): bool
    {
        return $this === self::PAID;
    }

    /**
     * True when the transaction ended without the merchant keeping the money.
     */
    public function isUnsuccessful(): bool
    {
        return match ($this) {
            self::FAILED, self::CANCELLED, self::EXPIRED, self::REFUNDED => true,
            default => false,
        };
    }

    /**
     * True while the transaction is still worth polling.
     */
    public function isPending(): bool
    {
        return ! $this->isFinal();
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing',
            self::AWAITING_3DS => 'Awaiting 3-D Secure authentication',
            self::AWAITING_OTP => 'Awaiting OTP',
            self::AUTHORISED => 'Authorised',
            self::PAID => 'Paid',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
            self::EXPIRED => 'Expired',
            self::REFUNDED => 'Refunded',
            self::PARTIALLY_REFUNDED => 'Partially refunded',
            self::UNKNOWN => 'Unknown',
        };
    }

    /**
     * Translate a raw gateway status string into a normalised case.
     *
     * Resolution order: exact config map hit, then a conservative built-in
     * vocabulary, then UNKNOWN. Matching is case-insensitive and ignores
     * separators so that "PARTIALLY_REFUNDED", "partially-refunded" and
     * "Partially Refunded" all resolve identically.
     */
    public static function fromGateway(?string $raw): self
    {
        if ($raw === null || trim($raw) === '') {
            return self::UNKNOWN;
        }

        $normalised = self::normalise($raw);

        foreach (self::overrides() as $gatewayValue => $case) {
            if (self::normalise((string) $gatewayValue) === $normalised) {
                return self::tryFrom(strtolower($case)) ?? self::UNKNOWN;
            }
        }

        return match ($normalised) {
            'pending', 'created', 'initiated', 'new' => self::PENDING,
            'processing', 'inprogress', 'sent', 'awaitingpayment', 'awaitingconfirmation' => self::PROCESSING,
            // Card 3DS challenge states returned by the MPGS express endpoint.
            'pending3ds', 'pending3d', 'authenticationrequired', 'awaiting3ds' => self::AWAITING_3DS,
            'awaitingotp', 'otpsent', 'pendingotp', 'otprequired' => self::AWAITING_OTP,
            'authorised', 'authorized', 'approved', 'authenticationsuccessful' => self::AUTHORISED,
            'paid', 'success', 'successful', 'completed', 'complete', 'settled' => self::PAID,
            'failed', 'failure', 'declined', 'rejected', 'error' => self::FAILED,
            'cancelled', 'canceled', 'aborted', 'abandoned' => self::CANCELLED,
            'expired', 'timeout', 'timedout' => self::EXPIRED,
            'refunded', 'reversed' => self::REFUNDED,
            'partiallyrefunded', 'partialrefund' => self::PARTIALLY_REFUNDED,
            default => self::UNKNOWN,
        };
    }

    /**
     * Merchant-supplied status overrides from config.
     *
     * Read defensively. This enum is a plain value object and gets used in
     * contexts with no booted container — a queue worker bootstrapping, a unit
     * test, a standalone script. Reaching for `config()` unguarded would turn
     * "parse a status string" into a fatal error in exactly those places.
     *
     * @return array<string, string>
     */
    private static function overrides(): array
    {
        if (! function_exists('app') || ! app()->bound('config')) {
            return [];
        }

        /** @var array<string, string> */
        return (array) config('smilepay.status_map', []);
    }

    /**
     * Lower-case and strip every non-alphanumeric character.
     */
    private static function normalise(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }
}
