<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Events;

use AaronKatema\SmilePay\DTO\TransactionSnapshot;
use AaronKatema\SmilePay\Models\SmilePayTransaction;

/**
 * A payment reached a terminal unsuccessful state — declined, expired,
 * insufficient funds.
 *
 * Distinct from a *gateway* failure (a timeout or 5xx), which leaves the
 * outcome unknown and is not dispatched here. Restock inventory and release
 * held stock on this event; do not on a network error.
 */
final class PaymentFailed
{

    public function __construct(
        public readonly TransactionSnapshot $snapshot,
        public readonly ?SmilePayTransaction $transaction = null,
        public readonly ?string $reason = null,
    ) {}

    public function orderReference(): string
    {
        return $this->snapshot->orderReference;
    }
}
