<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Enums;

/**
 * Which Smile&Pay environment the SDK is pointed at.
 */
enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    public static function fromLoose(self|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $value = strtolower(trim($value));

        return match ($value) {
            'production', 'prod', 'live' => self::Production,
            default => self::Sandbox,
        };
    }
}
