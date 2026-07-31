<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\DTO;

use AaronKatema\SmilePay\Enums\Currency;
use AaronKatema\SmilePay\Enums\PaymentMethod;
use AaronKatema\SmilePay\Enums\TransactionStatus;
use AaronKatema\SmilePay\Support\Msisdn;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use JsonSerializable;

/**
 * A point-in-time view of a transaction as Smile&Pay sees it.
 *
 * Produced by the status-check endpoint and by inbound callbacks. Both sources
 * carry the same field vocabulary, so one parser serves both — with the crucial
 * difference that a snapshot from the status endpoint is *authoritative* and one
 * parsed from a callback body is *a claim*, because the callback is unsigned.
 * `$verified` records which of the two you are holding, and the webhook pipeline
 * refuses to act on an unverified snapshot.
 */
final readonly class TransactionSnapshot implements JsonSerializable
{
    /**
     * @param  bool  $verified  True only when this came from an authenticated
     *                          server-to-server call, not an inbound callback body.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $orderReference,
        public TransactionStatus $status,
        public ?string $transactionReference = null,
        public ?Money $amount = null,
        public ?PaymentMethod $method = null,
        public ?Msisdn $mobileNumber = null,
        public ?string $merchantId = null,
        public ?string $itemName = null,
        public ?Money $clientFee = null,
        public ?Money $merchantFee = null,
        public ?DateTimeImmutable $createdAt = null,
        public bool $verified = false,
        public array $raw = [],
    ) {}

    /**
     * Parse a status-check response or a callback body.
     *
     * Every field is optional and every parse failure degrades to null rather
     * than throwing — a malformed fee field must not stop you from learning
     * that a payment succeeded. The one field that must be right is `status`,
     * and `TransactionStatus::fromGateway()` degrades unknown values to UNKNOWN
     * rather than guessing PAID.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(
        array $body,
        ?string $fallbackOrderReference = null,
        bool $verified = false,
    ): self {
        $currency = Currency::tryFromLoose(
            self::str($body, 'currency', 'currencyCode', 'currency_code')
        ) ?? Currency::USD;

        $orderReference = self::str($body, 'orderReference', 'order_reference', 'merchantReference')
            ?? $fallbackOrderReference
            ?? '';

        return new self(
            orderReference: $orderReference,
            status: TransactionStatus::fromGateway(
                self::str($body, 'status', 'transactionStatus', 'paymentStatus')
            ),
            transactionReference: self::str($body, 'reference', 'transactionReference', 'transaction_reference'),
            amount: self::money($body, $currency, 'amount'),
            method: PaymentMethod::tryFromLoose(
                self::str($body, 'paymentOption', 'paymentMethod', 'payment_option', 'payment_method')
            ),
            mobileNumber: Msisdn::tryParse(
                self::str($body, 'mobileNumber', 'mobilePhoneNumber', 'mobile_number', 'phone')
            ),
            merchantId: self::str($body, 'merchantId', 'merchant_id'),
            itemName: self::str($body, 'itemName', 'item_name'),
            clientFee: self::money($body, $currency, 'clientFee', 'client_fee'),
            merchantFee: self::money($body, $currency, 'merchantFee', 'merchant_fee'),
            createdAt: self::date($body, 'createdDate', 'created_date', 'createdAt', 'transactionDate'),
            verified: $verified,
            raw: $body,
        );
    }

    /**
     * Re-stamp this snapshot as verified.
     *
     * Only the gateway calls this, after an authenticated status check has
     * confirmed the values. It is intentionally not something a controller can
     * do to a callback body.
     */
    public function markVerified(): self
    {
        return new self(
            orderReference: $this->orderReference,
            status: $this->status,
            transactionReference: $this->transactionReference,
            amount: $this->amount,
            method: $this->method,
            mobileNumber: $this->mobileNumber,
            merchantId: $this->merchantId,
            itemName: $this->itemName,
            clientFee: $this->clientFee,
            merchantFee: $this->merchantFee,
            createdAt: $this->createdAt,
            verified: true,
            raw: $this->raw,
        );
    }

    public function isPaid(): bool
    {
        return $this->status->isSuccessful();
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    /**
     * Amount actually receivable after the merchant's share of the fee.
     */
    public function netAmount(): ?Money
    {
        if (! $this->amount instanceof Money) {
            return null;
        }

        if (! $this->merchantFee instanceof Money) {
            return $this->amount;
        }

        // Clamped rather than allowed to go negative: a fee exceeding the amount
        // is a gateway data problem, and throwing here would take down a status
        // page or a reconciliation run over a display value.
        return $this->merchantFee->greaterThan($this->amount)
            ? Money::zero($this->amount->currency)
            : $this->amount->minus($this->merchantFee);
    }

    /**
     * Confirm the money that arrived is the money that was asked for.
     *
     * A tampered or mismatched callback can carry the right reference and the
     * wrong amount. Checking both before releasing goods closes that gap, and
     * costs one comparison.
     */
    public function matches(string $orderReference, ?Money $expected = null): bool
    {
        if (! hash_equals($this->orderReference, $orderReference)) {
            return false;
        }

        if ($expected instanceof Money) {
            return $this->amount instanceof Money && $this->amount->equals($expected);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'order_reference' => $this->orderReference,
            'transaction_reference' => $this->transactionReference,
            'status' => $this->status->value,
            'amount' => $this->amount?->toDecimal(),
            'currency' => $this->amount?->currency->value,
            'method' => $this->method?->value,
            'mobile_number' => $this->mobileNumber?->masked(),
            'merchant_id' => $this->merchantId,
            'item_name' => $this->itemName,
            'client_fee' => $this->clientFee?->toDecimal(),
            'merchant_fee' => $this->merchantFee?->toDecimal(),
            'created_at' => $this->createdAt?->format(DateTimeInterface::ATOM),
            'verified' => $this->verified,
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
    private static function str(array $body, string ...$keys): ?string
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

    /**
     * @param  array<string, mixed>  $body
     */
    private static function money(array $body, Currency $currency, string ...$keys): ?Money
    {
        foreach ($keys as $key) {
            $value = $body[$key] ?? null;

            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
                return Money::fromDecimal($value, $currency);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function date(array $body, string ...$keys): ?DateTimeImmutable
    {
        foreach ($keys as $key) {
            $value = $body[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return new DateTimeImmutable($value);
            } catch (Exception) {
                continue;
            }
        }

        return null;
    }
}
