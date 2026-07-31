<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Contracts;

use AaronKatema\SmilePay\DTO\PaymentRequest;
use AaronKatema\SmilePay\DTO\PaymentResult;
use AaronKatema\SmilePay\DTO\TransactionSnapshot;
use AaronKatema\SmilePay\Models\SmilePayTransaction;

/**
 * Where the package records what it did.
 *
 * Abstracted so the audit trail is not welded to Eloquent. Merchants running a
 * read-replica setup, an event-sourced ledger, or no database at all can bind
 * their own implementation without forking the gateway.
 *
 * The store is the package's memory. Without it there is no way to answer the
 * question that matters after an outage: "which payments did we start but never
 * see the end of?"
 */
interface TransactionStore
{
    /**
     * Record the intent to charge, before the gateway is called.
     *
     * Writing first is what makes an indeterminate outcome — a timeout, a 502 —
     * recoverable. If the row does not exist and the call fails, the payment is
     * invisible and only the customer knows it happened.
     */
    public function starting(PaymentRequest $request): ?SmilePayTransaction;

    /**
     * Record the gateway's answer to an initiation attempt.
     */
    public function initiated(PaymentRequest $request, PaymentResult $result): ?SmilePayTransaction;

    /**
     * Record a verified state change from a status check or callback.
     */
    public function synced(TransactionSnapshot $snapshot): ?SmilePayTransaction;

    /**
     * Record that the gateway call itself failed, with the reason.
     */
    public function failed(PaymentRequest $request, string $reason): ?SmilePayTransaction;

    /**
     * Fetch by merchant order reference.
     */
    public function find(string $orderReference): ?SmilePayTransaction;

    /**
     * Fetch by the gateway's own transaction reference.
     *
     * Needed because the two-step OTP endpoints key on `transactionReference`
     * rather than the merchant's `orderReference` — without this lookup there
     * is no way to get back from leg 2 to the local row.
     */
    public function findByTransactionReference(string $transactionReference): ?SmilePayTransaction;

    /**
     * Whether this order reference has already reached a successful final
     * state. The guard that stops a replayed callback crediting an order twice.
     */
    public function isAlreadySettled(string $orderReference): bool;

    /**
     * Transactions still open past `$olderThanSeconds` — the reconciliation
     * work list.
     *
     * @return iterable<SmilePayTransaction>
     */
    public function pending(int $olderThanSeconds = 0, int $limit = 100): iterable;
}
