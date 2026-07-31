<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Events;

use AaronKatema\SmilePay\Models\SmilePayWebhookEvent;

/**
 * A callback arrived and was recorded, before verification.
 *
 * Fired for every inbound request that parses — including ones later rejected.
 * Do not act on the payload here: it is unauthenticated and may be forged.
 * This exists for observability, not for business logic.
 */
final class WebhookReceived
{

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly ?string $sourceIp = null,
        public readonly ?SmilePayWebhookEvent $event = null,
    ) {}
}
