<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Exceptions;

/**
 * Credentials were rejected (HTTP 401/403).
 *
 * Almost always a wrong key/secret pair, sandbox keys pointed at production, or
 * a merchant account that has not been approved yet. Never retried — repeating
 * a bad credential can trip lockout on the gateway side.
 */
final class AuthenticationException extends ApiException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
