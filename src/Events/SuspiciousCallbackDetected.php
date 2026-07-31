<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Events;

use AaronKatema\SmilePay\Models\SmilePayWebhookEvent;

/**
 * A callback claimed something the gateway does not confirm.
 *
 * The clearest example: a POST claiming `"status": "PAID"` for a reference the
 * status endpoint reports as PENDING, or does not recognise at all. Because
 * Smile&Pay callbacks carry no signature, this is the package's primary
 * intrusion signal.
 *
 * **Alert on this event.** A steady trickle usually means someone found your
 * webhook URL and is testing whether you check. A burst means they stopped
 * testing.
 */
final class SuspiciousCallbackDetected
{

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly string $reason,
        public readonly ?string $sourceIp = null,
        public readonly ?SmilePayWebhookEvent $event = null,
    ) {}
}
