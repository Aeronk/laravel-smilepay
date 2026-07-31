<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Support;

use AaronKatema\SmilePay\Enums\Currency;
use AaronKatema\SmilePay\Enums\Environment;
use AaronKatema\SmilePay\Exceptions\ConfigurationException;

/**
 * Validated, immutable configuration for one Smile&Pay merchant account.
 *
 * Resolving config into a typed object at boot means a missing API secret fails
 * on the first call with a message naming the exact `.env` key, rather than as
 * a 401 from ZB three weeks later in production. It also makes multi-merchant
 * setups trivial: build a second Config, get a second gateway.
 */
final readonly class Config
{
    /**
     * @param  array<string, mixed>  $defaults
     */
    public function __construct(
        public string $apiKey,
        public string $apiSecret,
        public string $baseUrl,
        public Environment $environment,
        public float $timeout = 30.0,
        public float $connectTimeout = 10.0,
        public int $retryAttempts = 3,
        public int $retryBaseDelayMs = 250,
        public int $retryMaxDelayMs = 4000,
        public bool $verifySsl = true,
        public string $userAgent = 'aaronkatema-laravel-smilepay/1.0',
        public bool $logRequests = true,
        public bool $persistTransactions = true,
        public bool $verifyCallbacks = true,
        public Currency $defaultCurrency = Currency::USD,
        public array $defaults = [],
    ) {
        if (trim($apiKey) === '') {
            throw ConfigurationException::missingCredential('key');
        }

        if (trim($apiSecret) === '') {
            throw ConfigurationException::missingCredential('secret');
        }

        if (trim($baseUrl) === '') {
            throw ConfigurationException::missingBaseUrl($environment->value);
        }

        if ($timeout <= 0) {
            throw ConfigurationException::invalidValue('timeout', 'must be greater than zero');
        }

        if ($retryAttempts < 1) {
            throw ConfigurationException::invalidValue('retry.attempts', 'must be at least 1');
        }

        if (! $verifySsl && $environment->isProduction()) {
            throw ConfigurationException::invalidValue(
                'verify_ssl',
                'TLS verification cannot be disabled in production. Install the ZB certificate '
                .'chain on your server instead — disabling verification exposes live payment '
                .'credentials to interception.'
            );
        }
    }

    /**
     * Build from the published `config/smilepay.php` array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $environment = Environment::fromLoose(self::string($config, 'environment', 'sandbox'));

        /** @var array<string, mixed> $environments */
        $environments = self::arr($config, 'environments');

        /** @var array<string, mixed> $active */
        $active = is_array($environments[$environment->value] ?? null)
            ? $environments[$environment->value]
            : [];

        $baseUrl = self::string($active, 'base_url', '');

        if ($baseUrl === '') {
            throw ConfigurationException::missingBaseUrl($environment->value);
        }

        /** @var array<string, mixed> $retry */
        $retry = self::arr($config, 'retry');

        /** @var array<string, mixed> $http */
        $http = self::arr($config, 'http');

        return new self(
            apiKey: self::string($active, 'key', ''),
            apiSecret: self::string($active, 'secret', ''),
            baseUrl: rtrim($baseUrl, '/'),
            environment: $environment,
            timeout: (float) (self::scalar($http, 'timeout') ?? 30.0),
            connectTimeout: (float) (self::scalar($http, 'connect_timeout') ?? 10.0),
            retryAttempts: (int) (self::scalar($retry, 'attempts') ?? 3),
            retryBaseDelayMs: (int) (self::scalar($retry, 'base_delay_ms') ?? 250),
            retryMaxDelayMs: (int) (self::scalar($retry, 'max_delay_ms') ?? 4000),
            verifySsl: (bool) (self::scalar($http, 'verify_ssl') ?? true),
            userAgent: self::string($http, 'user_agent', 'aaronkatema-laravel-smilepay/1.0'),
            logRequests: (bool) (self::scalar($config, 'log_requests') ?? true),
            persistTransactions: (bool) (self::scalar(self::arr($config, 'database'), 'enabled') ?? true),
            verifyCallbacks: (bool) (self::scalar(self::arr($config, 'webhook'), 'verify_with_status_check') ?? true),
            defaultCurrency: Currency::fromLoose(self::string($config, 'default_currency', 'USD')),
            defaults: self::arr($config, 'defaults'),
        );
    }

    /**
     * Point this config at a different environment, keeping everything else.
     *
     * @param  array<string, mixed>  $credentials  ['key' => ..., 'secret' => ..., 'base_url' => ...]
     */
    public function forEnvironment(Environment $environment, array $credentials): self
    {
        return new self(
            apiKey: self::string($credentials, 'key', $this->apiKey),
            apiSecret: self::string($credentials, 'secret', $this->apiSecret),
            baseUrl: rtrim(self::string($credentials, 'base_url', $this->baseUrl), '/'),
            environment: $environment,
            timeout: $this->timeout,
            connectTimeout: $this->connectTimeout,
            retryAttempts: $this->retryAttempts,
            retryBaseDelayMs: $this->retryBaseDelayMs,
            retryMaxDelayMs: $this->retryMaxDelayMs,
            verifySsl: $this->verifySsl,
            userAgent: $this->userAgent,
            logRequests: $this->logRequests,
            persistTransactions: $this->persistTransactions,
            verifyCallbacks: $this->verifyCallbacks,
            defaultCurrency: $this->defaultCurrency,
            defaults: $this->defaults,
        );
    }

    public function defaultReturnUrl(): ?string
    {
        return self::nullableString($this->defaults, 'return_url');
    }

    public function defaultResultUrl(): ?string
    {
        return self::nullableString($this->defaults, 'result_url');
    }

    public function defaultItemName(): ?string
    {
        return self::nullableString($this->defaults, 'item_name');
    }

    public function defaultItemDescription(): ?string
    {
        return self::nullableString($this->defaults, 'item_description');
    }

    /**
     * One-line summary safe to print in a health check or `artisan about`.
     */
    public function describe(): string
    {
        return sprintf(
            'Smile&Pay [%s] %s key=%s',
            $this->environment->value,
            $this->baseUrl,
            Redactor::maskCredential($this->apiKey)
        );
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function string(array $source, string $key, string $default): string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function nullableString(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private static function arr(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function scalar(array $source, string $key): int|float|bool|string|null
    {
        $value = $source[$key] ?? null;

        return is_scalar($value) ? $value : null;
    }
}
