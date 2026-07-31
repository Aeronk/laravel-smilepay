<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Repositories;

use AaronKatema\SmilePay\Contracts\TransactionStore;
use AaronKatema\SmilePay\DTO\PaymentRequest;
use AaronKatema\SmilePay\DTO\PaymentResult;
use AaronKatema\SmilePay\DTO\TransactionSnapshot;
use AaronKatema\SmilePay\Models\SmilePayTransaction;

/**
 * A store that stores nothing.
 *
 * Bound when `smilepay.database.enabled` is false — for a stateless service
 * that keeps its own ledger elsewhere, or for tests that do not want a schema.
 *
 * Understand the trade: without a store the package cannot deduplicate
 * callbacks, cannot tell a replay from a first delivery, and cannot reconcile
 * after an outage. Anything using this is responsible for those three things
 * itself.
 *
 * Concretely, the "PaymentSucceeded fires exactly once per order reference"
 * guarantee DOES NOT HOLD with this store bound. Deduplication is implemented
 * by comparing against the previously recorded status, and there is nothing
 * recorded to compare against — so every duplicate callback and every poll of a
 * settled payment re-fires the event. Listeners must be idempotent on their
 * own, or `smilepay.database.enabled` must stay true.
 */
final class NullTransactionStore implements TransactionStore
{
    public function starting(PaymentRequest $request): ?SmilePayTransaction
    {
        return null;
    }

    public function initiated(PaymentRequest $request, PaymentResult $result): ?SmilePayTransaction
    {
        return null;
    }

    public function synced(TransactionSnapshot $snapshot): ?SmilePayTransaction
    {
        return null;
    }

    public function failed(PaymentRequest $request, string $reason): ?SmilePayTransaction
    {
        return null;
    }

    public function find(string $orderReference): ?SmilePayTransaction
    {
        return null;
    }

    public function findByTransactionReference(string $transactionReference): ?SmilePayTransaction
    {
        return null;
    }

    public function isAlreadySettled(string $orderReference): bool
    {
        return false;
    }

    public function pending(int $olderThanSeconds = 0, int $limit = 100): iterable
    {
        return [];
    }
}
