<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Events;

use AaronKatema\SmilePay\DTO\TransactionSnapshot;
use AaronKatema\SmilePay\Models\SmilePayTransaction;

/**
 * A payment is confirmed settled.
 *
 * **This is the only event that should release value** — ship the goods, grant
 * the licence, extend the subscription.
 *
 * Two guarantees make that safe:
 *
 * 1. The snapshot came from an authenticated status check, not from an unsigned
 *    callback body. `$snapshot->verified` is always true here.
 * 2. It fires exactly once per order reference. A duplicate or replayed
 *    callback for an already-settled transaction is recorded and dropped
 *    before reaching this point.
 *
 * Listeners should still be written to tolerate a repeat — a queue can redeliver
 * a job, and that is outside this package's control.
 */
final class PaymentSucceeded
{

    public function __construct(
        public readonly TransactionSnapshot $snapshot,
        public readonly ?SmilePayTransaction $transaction = null,
    ) {}

    public function orderReference(): string
    {
        return $this->snapshot->orderReference;
    }
}
