<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Exceptions;

use Throwable;

/**
 * The request never completed — DNS failure, TLS handshake, connect or read
 * timeout.
 *
 * As with a 5xx, this is an *indeterminate* outcome, not a failed payment. The
 * customer may already have been prompted on their handset.
 */
final class NetworkException extends SmilePayException
{
    public function __construct(
        string $message,
        public readonly ?string $url = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);

        $this->context = ['url' => $url];
    }

    public static function connectionFailed(string $url, Throwable $previous): self
    {
        return new self(
            sprintf('Could not reach Smile&Pay at %s: %s', $url, $previous->getMessage()),
            $url,
            $previous
        );
    }

    public static function timedOut(string $url, float $seconds): self
    {
        return new self(
            sprintf(
                'Smile&Pay did not respond within %.1fs at %s. The transaction outcome is '
                .'indeterminate — reconcile by merchant reference before retrying.',
                $seconds,
                $url
            ),
            $url
        );
    }

    public static function malformedResponse(string $url, string $snippet): self
    {
        return new self(
            sprintf('Smile&Pay returned a non-JSON response from %s: %s', $url, $snippet),
            $url
        );
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
