<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\DTO;

use AaronKatema\SmilePay\Enums\PaymentMethod;
use AaronKatema\SmilePay\Enums\TransactionStatus;
use AaronKatema\SmilePay\Support\ResponseCode;
use JsonSerializable;

/**
 * The outcome of initiating a payment, on any rail.
 *
 * `accepted` means Smile&Pay took the instruction — **not** that money moved.
 * On hosted checkout the customer has not seen the page yet; on a wallet push
 * their handset is only just ringing. Nothing in your application should treat
 * this object as proof of payment. Only a status check returning PAID, or a
 * callback that has been re-verified against the status endpoint, is proof.
 *
 * Each rail hands back a different "what now": a redirect URL, an InnBucks
 * code, a 3DS challenge, or an instruction to collect an OTP. Rather than a
 * result class per rail, that difference is expressed as `nextAction()`, so
 * a controller can switch once and handle every method.
 */
final readonly class PaymentResult implements JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw  The decoded gateway response, verbatim.
     */
    public function __construct(
        public bool $accepted,
        public string $orderReference,
        public ?string $transactionReference = null,
        public ?PaymentMethod $method = null,
        public ?string $paymentUrl = null,
        public ?string $responseCode = null,
        public ?string $message = null,
        public TransactionStatus $status = TransactionStatus::PENDING,
        public ?string $innbucksPaymentCode = null,
        public ?ThreeDSChallenge $challenge = null,
        public ?string $authenticationStatus = null,
        public ?string $gatewayRecommendation = null,
        public array $raw = [],
    ) {}

    /**
     * Parse a gateway response into a result.
     *
     * Field names are read defensively: the documented shape first, then common
     * variants. A gateway adding a field must never break parsing — but a
     * gateway whose success signal is absent must never be read as success.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(
        array $body,
        string $orderReference,
        ?PaymentMethod $method = null,
    ): self {
        $code = ResponseCode::normalise(self::pluck($body, 'responseCode', 'response_code', 'code'));
        $message = self::pluck($body, 'responseMessage', 'response_message', 'message', 'description');
        $accepted = ResponseCode::isSuccess($code);

        $rawStatus = self::pluck($body, 'status', 'transactionStatus', 'paymentStatus');
        $challenge = ThreeDSChallenge::fromResponse($body);

        // Resolve the lifecycle state. Order matters: an explicit status from
        // the gateway wins, then the shape of the response tells us what the
        // customer must do, and only failing both do we fall back to the
        // accepted flag. PAID is never inferred — initiation cannot settle.
        $status = match (true) {
            $rawStatus !== null => TransactionStatus::fromGateway($rawStatus),
            $challenge instanceof ThreeDSChallenge => TransactionStatus::AWAITING_3DS,
            $accepted && $method?->requiresOtp() === true => TransactionStatus::AWAITING_OTP,
            $accepted => TransactionStatus::PENDING,
            default => TransactionStatus::FAILED,
        };

        // A gateway that reports success in one field and a challenge in
        // another is still reporting a challenge.
        if ($accepted && $challenge instanceof ThreeDSChallenge && $status === TransactionStatus::PENDING) {
            $status = TransactionStatus::AWAITING_3DS;
        }

        // Initiation cannot settle a payment, whatever the body says. Some
        // gateways echo "SUCCESS" to mean "instruction accepted", and letting
        // that through would write status=paid with no verified_at — a
        // transaction the application believes is settled that no status check
        // ever confirmed. Only SmilePay::verify() may record PAID.
        if ($status === TransactionStatus::PAID || $status === TransactionStatus::AUTHORISED) {
            $status = TransactionStatus::PROCESSING;
        }

        return new self(
            accepted: $accepted,
            orderReference: $orderReference,
            transactionReference: self::pluck($body, 'transactionReference', 'transaction_reference', 'reference'),
            method: $method,
            paymentUrl: self::pluck($body, 'paymentUrl', 'payment_url', 'redirectUrl', 'url'),
            responseCode: $code,
            message: ResponseCode::describe($code, $message),
            status: $status,
            innbucksPaymentCode: self::pluck($body, 'innbucksPaymentCode', 'innbucks_payment_code', 'paymentCode'),
            challenge: $challenge,
            authenticationStatus: self::pluck($body, 'authenticationStatus', 'authentication_status'),
            gatewayRecommendation: self::pluck($body, 'gatewayRecommendation', 'gateway_recommendation'),
            raw: $body,
        );
    }

    /**
     * What your application must do next.
     *
     * Returns one of: `redirect`, `three_ds`, `innbucks_code`, `otp`, `poll`,
     * `failed`. Switch on this rather than on the payment method — it stays
     * correct when ZB changes which rails behave which way.
     */
    public function nextAction(): string
    {
        return match (true) {
            ! $this->accepted => 'failed',
            $this->challenge instanceof ThreeDSChallenge => 'three_ds',
            $this->paymentUrl !== null => 'redirect',
            $this->innbucksPaymentCode !== null => 'innbucks_code',
            $this->status === TransactionStatus::AWAITING_OTP => 'otp',
            default => 'poll',
        };
    }

    /** Send the customer to ZB's hosted page. */
    public function needsRedirect(): bool
    {
        return $this->nextAction() === 'redirect';
    }

    /** Render the 3DS challenge, then wait for the return URL. */
    public function needs3ds(): bool
    {
        return $this->nextAction() === 'three_ds';
    }

    /** Collect an SMS OTP and call `confirmOtp()` with `transactionReference`. */
    public function needsOtp(): bool
    {
        return $this->nextAction() === 'otp';
    }

    /** Show the InnBucks code and deep link, then poll. */
    public function needsInnbucksCode(): bool
    {
        return $this->nextAction() === 'innbucks_code';
    }

    /**
     * Deep link that opens the InnBucks app straight to this payment.
     *
     * Worth using on mobile: typing a six-digit code into another app is where
     * wallet checkouts lose customers.
     */
    public function innbucksDeepLink(): ?string
    {
        if ($this->innbucksPaymentCode === null) {
            return null;
        }

        return sprintf(
            'schinn.wbpycode://innbucks.co.zw?pymInnCode=%s',
            rawurlencode($this->innbucksPaymentCode)
        );
    }

    public function failed(): bool
    {
        return ! $this->accepted;
    }

    /**
     * Whether re-sending this exact request could plausibly succeed. Narrow by
     * design — retrying a decline annoys customers and trips issuer fraud rules.
     */
    public function isRetryable(): bool
    {
        return ! $this->accepted && ResponseCode::isRetryable($this->responseCode);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'accepted' => $this->accepted,
            'order_reference' => $this->orderReference,
            'transaction_reference' => $this->transactionReference,
            'method' => $this->method?->value,
            'status' => $this->status->value,
            'next_action' => $this->nextAction(),
            'payment_url' => $this->paymentUrl,
            'innbucks_payment_code' => $this->innbucksPaymentCode,
            'innbucks_deep_link' => $this->innbucksDeepLink(),
            'response_code' => $this->responseCode,
            'message' => $this->message,
            'authentication_status' => $this->authenticationStatus,
            'gateway_recommendation' => $this->gatewayRecommendation,
            'challenge' => $this->challenge?->toArray(),
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function pluck(array $body, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $body[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
