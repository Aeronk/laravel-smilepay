<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Exceptions;

/**
 * The gateway is throttling us (HTTP 429).
 *
 * Most often triggered by an over-eager poll loop. Respect `retryAfter()` when
 * it is present rather than backing off on a guess.
 */
final class RateLimitException extends ApiException
{
    /**
     * Seconds the gateway asked us to wait, if it said.
     */
    public function retryAfter(): ?int
    {
        $value = $this->body['retry_after'] ?? $this->body['retryAfter'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
