<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Exceptions;

/**
 * The gateway has no record of the transaction (HTTP 404).
 *
 * On a poll shortly after initiation this can be propagation lag rather than a
 * genuine absence, so the poller treats an early 404 as pending and only
 * surfaces this exception once the grace window has passed.
 */
final class TransactionNotFoundException extends ApiException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
