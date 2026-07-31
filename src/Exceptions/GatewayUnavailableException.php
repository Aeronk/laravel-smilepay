<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Exceptions;

/**
 * Smile&Pay returned a 5xx.
 *
 * Important nuance for reconciliation: a 5xx on *initiation* does not prove the
 * transaction was not created. Always reconcile by merchant reference before
 * assuming a retry is safe — this is why every request carries an idempotency
 * key and why the local transaction row is written before the call goes out.
 */
final class GatewayUnavailableException extends ApiException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
