<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Exceptions;

use Throwable;

/**
 * The gateway responded, but with a failure.
 *
 * Carries the HTTP status, the gateway's own error code and the decoded body so
 * that callers can branch on a specific decline reason without re-parsing JSON.
 */
class ApiException extends SmilePayException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $errorCode = null,
        public readonly array $body = [],
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);

        $this->context = [
            'status_code' => $statusCode,
            'error_code' => $errorCode,
            'request_id' => $requestId,
            'body' => $body,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(
        int $statusCode,
        array $body,
        ?string $requestId = null,
    ): self {
        $message = self::extractMessage($body)
            ?? sprintf('Smile&Pay returned HTTP %d with no error message.', $statusCode);

        $errorCode = self::extractCode($body);

        return match (true) {
            $statusCode === 401 || $statusCode === 403 => new AuthenticationException(
                $message, $statusCode, $errorCode, $body, $requestId
            ),
            $statusCode === 404 => new TransactionNotFoundException(
                $message, $statusCode, $errorCode, $body, $requestId
            ),
            $statusCode === 429 => new RateLimitException(
                $message, $statusCode, $errorCode, $body, $requestId
            ),
            $statusCode >= 500 => new GatewayUnavailableException(
                $message, $statusCode, $errorCode, $body, $requestId
            ),
            default => new self($message, $statusCode, $errorCode, $body, $requestId),
        };
    }

    /**
     * 5xx and 429 are worth another attempt; a 4xx decline is not.
     */
    public function isRetryable(): bool
    {
        return $this->statusCode >= 500 || $this->statusCode === 429;
    }

    /**
     * Pull an error message out of the body, tolerating several common shapes.
     *
     * @param  array<string, mixed>  $body
     */
    protected static function extractMessage(array $body): ?string
    {
        foreach (['message', 'error', 'error_description', 'detail', 'description', 'statusDescription'] as $key) {
            $value = $body[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        // Laravel-style validation payloads: {"errors": {"field": ["msg"]}}
        $errors = $body['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            $flat = [];

            array_walk_recursive($errors, static function ($item) use (&$flat): void {
                if (is_string($item)) {
                    $flat[] = $item;
                }
            });

            if ($flat !== []) {
                return implode(' ', $flat);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected static function extractCode(array $body): ?string
    {
        foreach (['code', 'error_code', 'errorCode', 'status_code', 'statusCode', 'status'] as $key) {
            $value = $body[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
