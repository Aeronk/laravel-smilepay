<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Http;

use AaronKatema\SmilePay\Exceptions\ApiException;
use AaronKatema\SmilePay\Exceptions\NetworkException;
use AaronKatema\SmilePay\Support\Config;
use AaronKatema\SmilePay\Support\Redactor;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Authenticated, retrying, logging transport for the Smile&Pay REST API.
 *
 * Deliberately the only place in the package that knows about HTTP. Everything
 * above it works in DTOs, so swapping transport or mocking the gateway in tests
 * touches one seam.
 *
 * Two policies here are worth stating outright:
 *
 * 1. **Only idempotent calls are retried.** A status check can be repeated
 *    freely. An initiate call cannot: the gateway may have already created the
 *    transaction and prompted the customer, and a blind retry risks charging
 *    them twice. Retries are therefore opt-in per call, not automatic.
 *
 * 2. **Secrets never reach the log.** Headers and bodies pass through a
 *    redactor before any logging. A payments log with live API secrets in it is
 *    a credential store nobody remembers is a credential store.
 */
final class Client
{
    private ClientInterface $http;

    public function __construct(
        private readonly Config $config,
        private readonly ?LoggerInterface $logger = null,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Guzzle([
            'timeout' => $this->config->timeout,
            'connect_timeout' => $this->config->connectTimeout,
            'http_errors' => false,
            'verify' => $this->config->verifySsl,
        ]);
    }

    /**
     * Swap the underlying transport. Used by the test fake.
     */
    public function withHandler(ClientInterface $http): self
    {
        $clone = new self($this->config, $this->logger, $http);

        return $clone;
    }

    /**
     * Pass null for endpoints that take no body — Smile&Pay's cancel endpoint
     * is a bodyless POST, and sending `[]` would serialise to a JSON array
     * rather than an object, which some gateways reject outright.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, ?array $payload = null, bool $idempotent = false): array
    {
        return $this->send('POST', $path, $payload, $idempotent);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $path): array
    {
        // GETs carry no side effects, so retrying one is always safe.
        return $this->send('GET', $path, null, true);
    }

    /**
     * Execute a request with bounded retries and full error translation.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     *
     * @throws ApiException|NetworkException
     */
    private function send(string $method, string $path, ?array $payload, bool $idempotent): array
    {
        $url = $this->url($path);
        $attempts = $idempotent ? max(1, $this->config->retryAttempts) : 1;
        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $startedAt = microtime(true);

            try {
                $response = $this->http->request($method, $url, $this->options($payload));

                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
                $status = $response->getStatusCode();
                $body = $this->decode($response, $url);

                $this->log($method, $url, $payload, $status, $body, $durationMs, $attempt);

                if ($status >= 400) {
                    $exception = ApiException::fromResponse(
                        $status,
                        $body,
                        $this->headerValue($response, 'x-request-id')
                    );

                    // A 5xx or 429 on an idempotent call is worth another go.
                    if ($exception->isRetryable() && $attempt < $attempts) {
                        $lastException = $exception;
                        $this->backoff($attempt, $exception instanceof \AaronKatema\SmilePay\Exceptions\RateLimitException
                            ? $exception->retryAfter()
                            : null);

                        continue;
                    }

                    throw $exception;
                }

                return $body;
            } catch (ConnectException $e) {
                $lastException = NetworkException::connectionFailed($url, $e);
            } catch (RequestException $e) {
                $lastException = NetworkException::connectionFailed($url, $e);
            } catch (TransferException $e) {
                $lastException = NetworkException::connectionFailed($url, $e);
            }

            $this->logFailure($method, $url, $payload, $lastException, $attempt);

            if ($attempt < $attempts) {
                $this->backoff($attempt);
            }
        }

        throw $lastException ?? NetworkException::timedOut($url, $this->config->timeout);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function options(?array $payload): array
    {
        $options = [
            'headers' => [
                'x-api-key' => $this->config->apiKey,
                'x-api-secret' => $this->config->apiSecret,
                'Accept' => 'application/json',
                'User-Agent' => $this->config->userAgent,
            ],
            'timeout' => $this->config->timeout,
            'connect_timeout' => $this->config->connectTimeout,
            'http_errors' => false,
            'verify' => $this->config->verifySsl,
        ];

        if ($payload !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['json'] = $payload;
        }

        return $options;
    }

    private function url(string $path): string
    {
        return rtrim($this->config->baseUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, string $url): array
    {
        $raw = (string) $response->getBody();

        if (trim($raw) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw NetworkException::malformedResponse($url, mb_substr($raw, 0, 200));
        }

        if (! is_array($decoded)) {
            return ['value' => $decoded];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Exponential backoff with full jitter.
     *
     * Jitter matters more than it looks: without it, every server that timed out
     * during a ZB outage retries in lockstep the moment the gateway returns and
     * knocks it straight back over.
     */
    private function backoff(int $attempt, ?int $retryAfterSeconds = null): void
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            usleep(min($retryAfterSeconds, 30) * 1_000_000);

            return;
        }

        $baseMs = $this->config->retryBaseDelayMs * (2 ** ($attempt - 1));
        $cappedMs = min($baseMs, $this->config->retryMaxDelayMs);

        usleep(random_int(0, (int) $cappedMs) * 1000);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $body
     */
    private function log(
        string $method,
        string $url,
        ?array $payload,
        int $status,
        array $body,
        int $durationMs,
        int $attempt,
    ): void {
        if (! $this->config->logRequests || ! $this->logger instanceof LoggerInterface) {
            return;
        }

        $this->logger->log(
            $status >= 400 ? 'warning' : 'info',
            'Smile&Pay request',
            [
                'method' => $method,
                'url' => $url,
                'attempt' => $attempt,
                'status' => $status,
                'duration_ms' => $durationMs,
                'request' => $payload === null ? null : Redactor::scrub($payload),
                'response' => Redactor::scrub($body),
            ]
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function logFailure(
        string $method,
        string $url,
        ?array $payload,
        ?Throwable $exception,
        int $attempt,
    ): void {
        $this->logger?->error('Smile&Pay request failed', [
            'method' => $method,
            'url' => $url,
            'attempt' => $attempt,
            'request' => $payload === null ? null : Redactor::scrub($payload),
            'error' => $exception?->getMessage(),
        ]);
    }

    private function headerValue(ResponseInterface $response, string $name): ?string
    {
        $value = $response->getHeaderLine($name);

        return $value === '' ? null : $value;
    }
}
