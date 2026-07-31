<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Webhooks;

use AaronKatema\SmilePay\DTO\TransactionSnapshot;

/**
 * What the handler decided about one callback.
 *
 * Note that `acknowledge()` is true for outcomes that were *not* acted on.
 * That is deliberate: acknowledgement is about whether ZB should stop retrying,
 * not about whether we believed them. Retrying a callback we rejected as forged
 * is pure noise, and returning a 500 to a probe confirms the endpoint is live
 * and reachable — useful information to hand an attacker for free.
 */
final readonly class CallbackOutcome
{
    private function __construct(
        public string $decision,
        public string $orderReference,
        public ?TransactionSnapshot $snapshot = null,
        public ?string $reason = null,
    ) {}

    /** Verified against the gateway and applied. */
    public static function verified(string $orderReference, TransactionSnapshot $snapshot): self
    {
        return new self('verified', $orderReference, $snapshot);
    }

    /** Already settled; nothing further to do. */
    public static function duplicate(string $orderReference): self
    {
        return new self('duplicate', $orderReference);
    }

    /** Disagreed with the gateway, or malformed. Not applied. */
    public static function rejected(string $orderReference, string $reason): self
    {
        return new self('rejected', $orderReference, null, $reason);
    }

    /** The status endpoint was unreachable. Left for reconciliation. */
    public static function verificationFailed(string $orderReference, string $reason): self
    {
        return new self('verification_failed', $orderReference, null, $reason);
    }

    /** Verification was disabled in config; the body was taken at face value. */
    public static function unverified(string $orderReference, TransactionSnapshot $snapshot): self
    {
        return new self('unverified', $orderReference, $snapshot);
    }

    public function wasApplied(): bool
    {
        return $this->decision === 'verified';
    }

    public function wasRejected(): bool
    {
        return $this->decision === 'rejected';
    }

    /**
     * Whether to tell Smile&Pay to stop retrying. Always true — see the class
     * docblock for why a rejected callback is still acknowledged.
     */
    public function acknowledge(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'decision' => $this->decision,
            'order_reference' => $this->orderReference ?: null,
            'status' => $this->snapshot?->status->value,
            'reason' => $this->reason,
        ], static fn ($value) => $value !== null);
    }
}
