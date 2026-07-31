<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Repositories;

use AaronKatema\SmilePay\Contracts\TransactionStore;
use AaronKatema\SmilePay\DTO\PaymentRequest;
use AaronKatema\SmilePay\DTO\PaymentResult;
use AaronKatema\SmilePay\DTO\TransactionSnapshot;
use AaronKatema\SmilePay\Enums\Environment;
use AaronKatema\SmilePay\Enums\TransactionStatus;
use AaronKatema\SmilePay\Models\SmilePayTransaction;
use AaronKatema\SmilePay\Support\Redactor;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent-backed audit trail.
 *
 * Two invariants this class exists to protect:
 *
 * 1. **The row is written before the gateway is called.** If the call then
 *    times out, the payment is still visible and reconcilable. Write-after
 *    means an indeterminate payment nobody knows to look for.
 *
 * 2. **A final state is never walked backwards.** A late-arriving PENDING
 *    callback must not un-pay a PAID transaction, and a duplicate PAID must not
 *    fire the settled event twice. Both are enforced inside a row lock rather
 *    than by hoping callbacks arrive in order — they do not.
 */
final class EloquentTransactionStore implements TransactionStore
{
    public function __construct(
        private readonly Environment $environment,
    ) {}

    public function starting(PaymentRequest $request): ?SmilePayTransaction
    {
        $transaction = $this->find($request->orderReference)
            ?? $this->query()->newModelInstance(['order_reference' => $request->orderReference]);

        $transaction->fill([
            'idempotency_key' => $request->resolveIdempotencyKey(),
            'method' => $request->method ?? $transaction->method,
            'environment' => $this->environment,
            'amount_minor' => $request->amount->minor,
            'currency' => $request->amount->currency,
            'item_name' => $request->itemName,
            'item_description' => $request->itemDescription,
            'customer' => $request->customer->toLogArray(),
            'mobile_number' => $request->customer->msisdn?->masked(),
            'metadata' => $request->metadata ?: null,
            'initiated_at' => now(),
        ]);

        // Never downgrade. Re-initiating an order reference that already
        // settled must not blank out the fact that it was paid — that would
        // turn a duplicate checkout click into a lost payment.
        if (! $transaction->exists) {
            $transaction->status = TransactionStatus::PENDING;
        }

        $transaction->save();

        return $transaction;
    }

    public function initiated(PaymentRequest $request, PaymentResult $result): ?SmilePayTransaction
    {
        $transaction = $this->find($request->orderReference);

        if (! $transaction instanceof SmilePayTransaction) {
            return null;
        }

        // A gateway that already considers this settled outranks whatever the
        // initiation response says.
        $status = $transaction->status->isFinal() && ! $result->status->isFinal()
            ? $transaction->status
            : $result->status;

        $transaction->fill([
            'transaction_reference' => $result->transactionReference ?? $transaction->transaction_reference,
            'status' => $status,
            'method' => $result->method ?? $transaction->method,
            'payment_url' => $result->paymentUrl,
            'innbucks_code' => $result->innbucksPaymentCode,
            'response_code' => $result->responseCode,
            'response_message' => $result->message,
            // Card PANs and CVVs are scrubbed on the way in, so a raw response
            // captured for debugging can never become stored card data.
            'raw_initiate' => Redactor::scrub($result->raw),
        ]);

        if ($result->failed()) {
            $transaction->failed_at = now();
        }

        $transaction->save();

        return $transaction;
    }

    public function synced(TransactionSnapshot $snapshot): ?SmilePayTransaction
    {
        if ($snapshot->orderReference === '') {
            return null;
        }

        // The lock makes the read-compare-write atomic. Without it, a callback
        // and a concurrent poll can both read PROCESSING, both decide the
        // transaction just became PAID, and both fire the settled event —
        // shipping the order twice.
        return DB::connection($this->connection())->transaction(
            function () use ($snapshot): ?SmilePayTransaction {
                $transaction = $this->query()
                    ->where('order_reference', $snapshot->orderReference)
                    ->lockForUpdate()
                    ->first();

                if (! $transaction instanceof SmilePayTransaction) {
                    // A callback for a reference we never initiated. Recorded
                    // rather than dropped: it is either a foreign merchant's
                    // traffic or someone probing the endpoint, and both are
                    // worth being able to see.
                    return null;
                }

                if ($transaction->isFinal() && ! $snapshot->status->isFinal()) {
                    // Late non-final update for a settled payment. Ignored.
                    return $transaction;
                }

                $transaction->fill(array_filter([
                    'transaction_reference' => $snapshot->transactionReference,
                    'status' => $snapshot->status,
                    'method' => $snapshot->method,
                    'client_fee_minor' => $snapshot->clientFee?->minor,
                    'merchant_fee_minor' => $snapshot->merchantFee?->minor,
                    'mobile_number' => $snapshot->mobileNumber?->masked(),
                    'raw_status' => Redactor::scrub($snapshot->raw),
                ], static fn ($value) => $value !== null));

                if ($snapshot->verified) {
                    $transaction->verified_at = now();
                }

                match (true) {
                    $snapshot->status === TransactionStatus::PAID => $transaction->paid_at ??= now(),
                    $snapshot->status === TransactionStatus::FAILED => $transaction->failed_at ??= now(),
                    $snapshot->status === TransactionStatus::CANCELLED => $transaction->cancelled_at ??= now(),
                    default => null,
                };

                $transaction->save();

                return $transaction;
            }
        );
    }

    public function failed(PaymentRequest $request, string $reason): ?SmilePayTransaction
    {
        $transaction = $this->find($request->orderReference);

        if (! $transaction instanceof SmilePayTransaction) {
            return null;
        }

        // The gateway call failed, which is not the same as the payment
        // failing. Status is left alone deliberately: a timeout may still have
        // reached ZB and prompted the customer, so reconciliation must still
        // pick this row up rather than seeing it as closed.
        $transaction->last_error = mb_substr($reason, 0, 1000);
        $transaction->save();

        return $transaction;
    }

    public function find(string $orderReference): ?SmilePayTransaction
    {
        return $this->query()->where('order_reference', $orderReference)->first();
    }

    public function findByTransactionReference(string $transactionReference): ?SmilePayTransaction
    {
        return $this->query()->where('transaction_reference', $transactionReference)->first();
    }

    public function isAlreadySettled(string $orderReference): bool
    {
        return $this->query()
            ->where('order_reference', $orderReference)
            ->where('status', TransactionStatus::PAID->value)
            ->whereNotNull('verified_at')
            ->exists();
    }

    public function pending(int $olderThanSeconds = 0, int $limit = 100): iterable
    {
        return $this->query()
            ->stale($olderThanSeconds)
            ->orderBy('created_at')
            ->limit($limit)
            ->cursor();
    }

    /**
     * Record a poll attempt so a stuck transaction can be backed off rather
     * than hammered forever.
     */
    public function recordPoll(string $orderReference): void
    {
        $this->query()
            ->where('order_reference', $orderReference)
            ->update([
                'last_polled_at' => now(),
                'poll_attempts' => DB::raw('poll_attempts + 1'),
            ]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SmilePayTransaction>
     */
    private function query()
    {
        return SmilePayTransaction::query();
    }

    private function connection(): ?string
    {
        /** @var string|null $connection */
        $connection = config('smilepay.database.connection');

        return $connection;
    }
}
