<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Exceptions;

use RuntimeException;

/**
 * Base for every exception this package throws.
 *
 * Catching this one type is enough to isolate all gateway-related failure in a
 * host application, which matters when Smile&Pay sits inside a larger checkout
 * that must degrade gracefully rather than 500.
 */
abstract class SmilePayException extends RuntimeException
{
    /** @var array<string, mixed> */
    protected array $context = [];

    /**
     * Structured detail safe to attach to logs. Credentials are never included.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): static
    {
        $clone = clone $this;
        $clone->context = [...$this->context, ...$context];

        return $clone;
    }

    /**
     * Whether retrying the same call has any chance of a different outcome.
     */
    public function isRetryable(): bool
    {
        return false;
    }
}
