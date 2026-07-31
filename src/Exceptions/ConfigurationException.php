<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Exceptions;

/**
 * The package is misconfigured — thrown at boot or first use, never mid-payment.
 */
final class ConfigurationException extends SmilePayException
{
    public static function missingCredential(string $key): self
    {
        return new self(sprintf(
            'Smile&Pay credential [%s] is not set. Add SMILEPAY_%s to your .env file.',
            $key,
            strtoupper($key)
        ));
    }

    public static function missingBaseUrl(string $environment): self
    {
        return new self(sprintf(
            'No base URL configured for the [%s] Smile&Pay environment. '
            .'Set smilepay.environments.%s.base_url in config/smilepay.php.',
            $environment,
            $environment
        ));
    }

    public static function missingWebhookSecret(): self
    {
        return new self(
            'Webhook signature verification is enabled but no secret is configured. '
            .'Set SMILEPAY_WEBHOOK_SECRET, or disable verification with '
            .'SMILEPAY_VERIFY_WEBHOOK_SIGNATURE=false (not recommended in production).'
        );
    }

    public static function invalidValue(string $key, string $reason): self
    {
        return new self(sprintf('Invalid Smile&Pay config value for [%s]: %s', $key, $reason));
    }
}
