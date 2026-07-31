<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\DTO;

use AaronKatema\SmilePay\Enums\Currency;
use AaronKatema\SmilePay\Enums\PaymentMethod;
use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;
use AaronKatema\SmilePay\Support\Msisdn;
use Illuminate\Support\Str;
use JsonSerializable;

/**
 * An immutable instruction to collect money from a customer.
 *
 * Built fluently, validated once, then handed to the gateway:
 *
 *     PaymentRequest::make('ORDER-12345', 100.00, 'USD')
 *         ->withItem('Premium Subscription', '1 Month Premium Access')
 *         ->withMethod(PaymentMethod::ECOCASH)
 *         ->withCustomer(Customer::make('0771234567', 'a@example.com'))
 *         ->withMetadata(['tenant_id' => 7]);
 *
 * Validation is strict and entirely local. A request that cannot succeed is
 * rejected before it creates a database row, a gateway record, or — worst of
 * the three — a payment prompt on a customer's handset that nothing will ever
 * resolve.
 *
 * `metadata` never reaches Smile&Pay; the API has no custom-field support. It
 * is persisted on the local transaction row and rejoined by `orderReference`
 * when a callback arrives, which is how tenant IDs and cart IDs survive the
 * round trip.
 */
final readonly class PaymentRequest implements JsonSerializable
{
    /**
     * Conservative cap. The API does not document a limit; this length is safe
     * across the gateway, the URL path used by the status-check endpoint, and
     * the indexed column in the local transactions table.
     */
    public const MAX_REFERENCE_LENGTH = 64;

    /**
     * @param  array<string, mixed>  $metadata  Merchant-side data, stored locally only.
     */
    public function __construct(
        public string $orderReference,
        public Money $amount,
        public ?PaymentMethod $method = null,
        public Customer $customer = new Customer,
        public ?string $itemName = null,
        public ?string $itemDescription = null,
        public ?string $returnUrl = null,
        public ?string $resultUrl = null,
        public array $metadata = [],
        public ?string $idempotencyKey = null,
    ) {}

    /**
     * Primary constructor. Amount accepts a Money, a decimal string or a number.
     *
     * Leaving `$method` null is valid and meaningful on hosted checkout: the
     * customer picks their own rail on ZB's page.
     */
    public static function make(
        string $orderReference,
        Money|int|float|string $amount,
        Currency|string|int $currency = Currency::USD,
        PaymentMethod|string|null $method = null,
    ): self {
        return new self(
            orderReference: trim($orderReference),
            amount: $amount instanceof Money ? $amount : Money::fromDecimal($amount, $currency),
            method: $method === null ? null : PaymentMethod::fromLoose($method),
        );
    }

    public function withItem(string $name, ?string $description = null): self
    {
        return $this->copy(
            itemName: trim($name),
            itemDescription: $description === null ? trim($name) : trim($description),
        );
    }

    public function withCustomer(Customer $customer): self
    {
        return $this->copy(customer: $customer);
    }

    /**
     * Shortcut for the common wallet case where only a number is known.
     */
    public function withMsisdn(Msisdn|string $msisdn): self
    {
        return $this->copy(customer: $this->customer->withMsisdn($msisdn));
    }

    public function withEmail(string $email): self
    {
        return $this->copy(customer: $this->customer->withEmail($email));
    }

    public function withMethod(PaymentMethod|string $method): self
    {
        return $this->copy(method: PaymentMethod::fromLoose($method));
    }

    public function withReturnUrl(string $url): self
    {
        return $this->copy(returnUrl: self::assertUrl('returnUrl', $url));
    }

    public function withWebhookUrl(string $url): self
    {
        return $this->copy(resultUrl: self::assertUrl('resultUrl', $url));
    }

    /**
     * Merge merchant metadata. Colliding keys are last-write-wins.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return $this->copy(metadata: [...$this->metadata, ...$metadata]);
    }

    public function withIdempotencyKey(string $key): self
    {
        return $this->copy(idempotencyKey: $key);
    }

    /**
     * Fill any blank URL or item field from package config.
     *
     * Called by the gateway immediately before dispatch so that merchants can
     * set sensible defaults once in config rather than on every request.
     */
    public function withDefaults(
        ?string $returnUrl = null,
        ?string $resultUrl = null,
        ?string $itemName = null,
        ?string $itemDescription = null,
    ): self {
        return $this->copy(
            itemName: $this->itemName ?? $itemName,
            itemDescription: $this->itemDescription ?? $itemDescription ?? $this->itemName ?? $itemName,
            returnUrl: $this->returnUrl ?? $returnUrl,
            resultUrl: $this->resultUrl ?? $resultUrl,
        );
    }

    /**
     * The key that makes a retry safe.
     *
     * Smile&Pay does not document an idempotency header, so this value is used
     * package-side: it deduplicates local transaction rows and lets the retry
     * logic recognise that a timed-out call and its replacement are the same
     * intent. Derived from the reference and amount, so changing either
     * deliberately produces a genuinely new payment.
     */
    public function resolveIdempotencyKey(): string
    {
        return $this->idempotencyKey ?? hash(
            'sha256',
            $this->orderReference.'|'.$this->amount->minor.'|'.$this->amount->currency->value
        );
    }

    /**
     * Validate everything checkable without a network call.
     *
     * @param  bool  $express  Apply Express Checkout rules instead of hosted rules.
     *
     * @throws InvalidPaymentRequestException
     */
    public function validate(bool $express = false): void
    {
        if ($this->orderReference === '') {
            throw InvalidPaymentRequestException::missingReference();
        }

        if (Str::length($this->orderReference) > self::MAX_REFERENCE_LENGTH) {
            throw InvalidPaymentRequestException::referenceTooLong(
                self::MAX_REFERENCE_LENGTH,
                Str::length($this->orderReference)
            );
        }

        if (! $this->amount->isPositive()) {
            throw InvalidPaymentRequestException::zeroAmount();
        }

        if ($this->resultUrl === null) {
            throw InvalidPaymentRequestException::missingResultUrl();
        }

        if ($express) {
            $this->validateExpress();

            return;
        }

        if ($this->itemName === null || $this->itemDescription === null) {
            throw InvalidPaymentRequestException::missingItemDetails();
        }

        if ($this->returnUrl === null) {
            throw InvalidPaymentRequestException::missingReturnUrl();
        }
    }

    /**
     * Non-fatal advisories worth logging or showing a developer.
     *
     * These are warnings rather than exceptions because the gateway is the
     * final authority on what it accepts; a hard failure here would break
     * working merchants the day a network is issued a new prefix.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        if ($this->method?->network() !== null
            && $this->customer->msisdn instanceof Msisdn
            && $this->customer->msisdn->network() !== $this->method->network()) {
            $warnings[] = sprintf(
                'Number %s is on %s but the selected method is %s (%s). This will likely be declined.',
                $this->customer->msisdn->masked(),
                $this->customer->msisdn->network(),
                $this->method->label(),
                $this->method->network()
            );
        }

        if ($this->method?->requiresOtp() === true) {
            $warnings[] = sprintf(
                '%s is a two-step rail. Collect the SMS OTP from the customer and call '
                .'confirmOtp() with the returned transaction reference.',
                $this->method->label()
            );
        }

        return $warnings;
    }

    /**
     * Exact payload for POST /payments/initiate-transaction.
     *
     * @return array<string, mixed>
     */
    public function toGatewayPayload(): array
    {
        return array_filter([
            'orderReference' => $this->orderReference,
            'amount' => round($this->amount->toFloat(), $this->amount->currency->decimals()),
            'currencyCode' => $this->amount->currency->numericCode(),
            'itemName' => $this->itemName,
            'itemDescription' => $this->itemDescription,
            'returnUrl' => $this->returnUrl,
            'resultUrl' => $this->resultUrl,
            'paymentMethod' => $this->method?->value,
            ...$this->customer->toGatewayPayload(),
        ], static fn ($value) => $value !== null);
    }

    /**
     * Neutral array form for logging, testing and persistence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'order_reference' => $this->orderReference,
            'amount' => $this->amount->toDecimal(),
            'currency' => $this->amount->currency->value,
            'method' => $this->method?->value,
            'customer' => $this->customer->isEmpty() ? null : $this->customer->toArray(),
            'item_name' => $this->itemName,
            'item_description' => $this->itemDescription,
            'return_url' => $this->returnUrl,
            'result_url' => $this->resultUrl,
            'metadata' => $this->metadata ?: null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function validateExpress(): void
    {
        if ($this->method === null) {
            throw InvalidPaymentRequestException::missingMethodForExpress();
        }

        if (! $this->method->supportsExpressCheckout()) {
            throw InvalidPaymentRequestException::expressCheckoutNotSupported($this->method->value);
        }

        if ($this->method->requiresMsisdn() && ! $this->customer->msisdn instanceof Msisdn) {
            throw InvalidPaymentRequestException::missingMsisdn($this->method->value);
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function copy(
        ?string $orderReference = null,
        ?Money $amount = null,
        ?PaymentMethod $method = null,
        ?Customer $customer = null,
        ?string $itemName = null,
        ?string $itemDescription = null,
        ?string $returnUrl = null,
        ?string $resultUrl = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
    ): self {
        return new self(
            orderReference: $orderReference ?? $this->orderReference,
            amount: $amount ?? $this->amount,
            method: $method ?? $this->method,
            customer: $customer ?? $this->customer,
            itemName: $itemName ?? $this->itemName,
            itemDescription: $itemDescription ?? $this->itemDescription,
            returnUrl: $returnUrl ?? $this->returnUrl,
            resultUrl: $resultUrl ?? $this->resultUrl,
            metadata: $metadata ?? $this->metadata,
            idempotencyKey: $idempotencyKey ?? $this->idempotencyKey,
        );
    }

    private static function assertUrl(string $field, string $url): string
    {
        $url = trim($url);

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            throw InvalidPaymentRequestException::invalidUrl($field, $url);
        }

        return $url;
    }
}
