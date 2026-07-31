<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Webhooks;

use AaronKatema\SmilePay\DTO\TransactionSnapshot;
use AaronKatema\SmilePay\Enums\TransactionStatus;
use AaronKatema\SmilePay\Events\SuspiciousCallbackDetected;
use AaronKatema\SmilePay\Events\WebhookReceived;
use AaronKatema\SmilePay\Exceptions\SmilePayException;
use AaronKatema\SmilePay\Models\SmilePayWebhookEvent;
use AaronKatema\SmilePay\SmilePay;
use AaronKatema\SmilePay\Support\Redactor;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * Turns an untrusted callback into a verified state change — or into an alert.
 *
 * ## Why this class is shaped the way it is
 *
 * Smile&Pay callbacks are unsigned. There is no HMAC, no shared secret, no
 * mutual TLS — just a JSON body posted to whatever URL you configured. The URL
 * is not a secret either: it travels in every `resultUrl` you send and appears
 * in the callback body itself.
 *
 * The naive handler reads `status: "PAID"` and marks the order paid. That
 * handler ships goods to anyone who can write a POST request.
 *
 * So the body is treated as a *hint*, never as evidence. Every callback
 * triggers an authenticated status check against ZB, and only that answer is
 * allowed to change anything. When the two disagree — a body claiming PAID for
 * a transaction the gateway calls PENDING — that is recorded and dispatched as
 * SuspiciousCallbackDetected, because it is far more likely to be an attack
 * than a coincidence.
 *
 * The handler always returns success to ZB, even for a rejected callback.
 * Signalling failure only makes the gateway retry a request we have already
 * decided not to trust, and tells whoever sent a forged one that we noticed.
 */
final class CallbackHandler
{
    public function __construct(
        private readonly SmilePay $gateway,
        private readonly ?Dispatcher $events = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $persistEvents = true,
        private readonly bool $verifyWithStatusCheck = true,
    ) {}

    /**
     * Process one inbound callback.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, ?string $sourceIp = null): CallbackOutcome
    {
        $orderReference = $this->pluck($payload, 'orderReference', 'order_reference', 'merchantReference');

        // Every key variant TransactionSnapshot understands must be read here
        // too. A forgery using `transactionStatus` instead of `status` would
        // otherwise parse as UNKNOWN, silently "agree" with the gateway, and
        // slip past the intrusion alert — state would stay safe, but the signal
        // that someone is probing would be lost.
        $claimed = TransactionStatus::fromGateway(
            $this->pluck($payload, 'status', 'transactionStatus', 'paymentStatus')
        );

        $event = $this->record($payload, $orderReference, $claimed, $sourceIp);

        $this->events?->dispatch(new WebhookReceived($payload, $sourceIp, $event));

        if ($orderReference === null) {
            return $this->reject($event, $payload, $sourceIp, 'callback carried no orderReference');
        }

        // Idempotency. ZB retries until it gets a 200, so duplicates are the
        // normal case, not the exception. A transaction already verified as
        // settled needs no further work — and re-running the pipeline would
        // risk a second PaymentSucceeded if anything upstream ever changed.
        if ($this->gateway->store()->isAlreadySettled($orderReference)) {
            $this->finish($event, verified: true, status: TransactionStatus::PAID, actedOn: false);

            return CallbackOutcome::duplicate($orderReference);
        }

        if (! $this->verifyWithStatusCheck) {
            // Explicitly opted out via config. Recorded on the event row so an
            // auditor can see the guarantee was disabled, and when.
            $this->logger?->warning(
                'Smile&Pay callback accepted without verification — smilepay.webhook.'
                .'verify_with_status_check is disabled. Unsigned callbacks are forgeable.',
                ['order_reference' => $orderReference]
            );

            $snapshot = TransactionSnapshot::fromArray($payload, $orderReference, verified: false);

            $this->finish($event, verified: false, status: $claimed, actedOn: false);

            return CallbackOutcome::unverified($orderReference, $snapshot);
        }

        try {
            // The authoritative answer, over our own credentialled channel.
            $snapshot = $this->gateway->verify($orderReference);
        } catch (SmilePayException $e) {
            $this->logger?->error('Smile&Pay callback verification failed', [
                'order_reference' => $orderReference,
                'error' => $e->getMessage(),
            ]);

            $this->finish($event, verified: false, status: null, actedOn: false, reason: $e->getMessage());

            // Deliberately reported as handled. ZB retrying will not fix an
            // outage on their own status endpoint, and the scheduled
            // reconciliation pass will pick this transaction up regardless.
            return CallbackOutcome::verificationFailed($orderReference, $e->getMessage());
        }

        $agrees = $claimed === TransactionStatus::UNKNOWN || $claimed === $snapshot->status;

        if (! $agrees && $claimed === TransactionStatus::PAID) {
            // Someone claimed money arrived and the gateway says otherwise.
            $reason = sprintf(
                'callback claimed PAID but the gateway reports %s',
                $snapshot->status->value
            );

            $this->finish($event, verified: false, status: $snapshot->status, actedOn: false, reason: $reason);

            $this->events?->dispatch(
                new SuspiciousCallbackDetected($payload, $reason, $sourceIp, $event)
            );

            $this->logger?->alert('Smile&Pay: forged or stale PAID callback rejected', [
                'order_reference' => $orderReference,
                'claimed' => $claimed->value,
                'actual' => $snapshot->status->value,
                'source_ip' => $sourceIp,
            ]);

            return CallbackOutcome::rejected($orderReference, $reason);
        }

        $this->finish(
            $event,
            verified: true,
            status: $snapshot->status,
            actedOn: true,
            verificationResponse: $snapshot->raw
        );

        return CallbackOutcome::verified($orderReference, $snapshot);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(
        array $payload,
        ?string $orderReference,
        TransactionStatus $claimed,
        ?string $sourceIp,
    ): ?SmilePayWebhookEvent {
        if (! $this->persistEvents) {
            return null;
        }

        return SmilePayWebhookEvent::create([
            'order_reference' => $orderReference ?? '',
            'transaction_reference' => $this->pluck($payload, 'reference', 'transactionReference', 'transaction_reference'),
            'claimed_status' => $claimed,
            'verified' => false,
            'acted_on' => false,
            'source_ip' => $sourceIp,
            // Scrubbed on the way in: a webhook table is a long-lived store and
            // has no business holding raw contact details.
            'payload' => Redactor::scrub($payload),
            'received_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $verificationResponse
     */
    private function finish(
        ?SmilePayWebhookEvent $event,
        bool $verified,
        ?TransactionStatus $status,
        bool $actedOn,
        ?string $reason = null,
        ?array $verificationResponse = null,
    ): void {
        if (! $event instanceof SmilePayWebhookEvent) {
            return;
        }

        $event->fill(array_filter([
            'verified' => $verified,
            'acted_on' => $actedOn,
            'verified_status' => $status,
            'rejection_reason' => $reason,
            'verification_response' => $verificationResponse === null
                ? null
                : Redactor::scrub($verificationResponse),
        ], static fn ($value) => $value !== null));

        $event->verified = $verified;
        $event->acted_on = $actedOn;
        $event->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reject(
        ?SmilePayWebhookEvent $event,
        array $payload,
        ?string $sourceIp,
        string $reason,
    ): CallbackOutcome {
        $this->finish($event, verified: false, status: null, actedOn: false, reason: $reason);

        $this->events?->dispatch(new SuspiciousCallbackDetected($payload, $reason, $sourceIp, $event));

        $this->logger?->warning('Smile&Pay callback rejected', [
            'reason' => $reason,
            'source_ip' => $sourceIp,
        ]);

        return CallbackOutcome::rejected('', $reason);
    }

    /**
     * First non-blank string value among the given keys.
     *
     * @param  array<string, mixed>  $payload
     */
    private function pluck(array $payload, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
