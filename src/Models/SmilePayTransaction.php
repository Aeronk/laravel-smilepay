<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Models;

use AaronKatema\SmilePay\DTO\Money;
use AaronKatema\SmilePay\Enums\Currency;
use AaronKatema\SmilePay\Enums\Environment;
use AaronKatema\SmilePay\Enums\PaymentMethod;
use AaronKatema\SmilePay\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The local record of a Smile&Pay payment.
 *
 * This is the row your application should trust — not the gateway response,
 * and certainly not the callback body. It is written before the gateway is
 * called and updated only from verified sources, which is what makes the
 * question "did order X get paid?" answerable without a network call.
 *
 * @property int $id
 * @property string $order_reference
 * @property string|null $transaction_reference
 * @property string|null $idempotency_key
 * @property TransactionStatus $status
 * @property PaymentMethod|null $method
 * @property Environment $environment
 * @property int $amount_minor
 * @property Currency $currency
 * @property int|null $client_fee_minor
 * @property int|null $merchant_fee_minor
 * @property string|null $item_name
 * @property string|null $item_description
 * @property array<string, mixed>|null $customer
 * @property string|null $mobile_number
 * @property string|null $payment_url
 * @property string|null $innbucks_code
 * @property string|null $response_code
 * @property string|null $response_message
 * @property string|null $last_error
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $raw_initiate
 * @property array<string, mixed>|null $raw_status
 * @property \Illuminate\Support\Carbon|null $initiated_at
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $failed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $last_polled_at
 * @property int $poll_attempts
 */
class SmilePayTransaction extends Model
{
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'method' => PaymentMethod::class,
            'environment' => Environment::class,
            'currency' => Currency::class,
            'amount_minor' => 'integer',
            'client_fee_minor' => 'integer',
            'merchant_fee_minor' => 'integer',
            'poll_attempts' => 'integer',
            'customer' => 'array',
            'metadata' => 'array',
            'raw_initiate' => 'array',
            'raw_status' => 'array',
            'initiated_at' => 'datetime',
            'verified_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_polled_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? (string) config('smilepay.database.transactions_table', 'smilepay_transactions');
    }

    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('smilepay.database.connection');
    }

    /**
     * Whatever this payment is for — an Order, Invoice, Subscription.
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Every callback ever received for this reference, in order.
     */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(SmilePayWebhookEvent::class, 'order_reference', 'order_reference');
    }

    public function amount(): Money
    {
        return Money::fromMinor($this->amount_minor, $this->currency);
    }

    public function clientFee(): ?Money
    {
        return $this->client_fee_minor === null
            ? null
            : Money::fromMinor($this->client_fee_minor, $this->currency);
    }

    public function merchantFee(): ?Money
    {
        return $this->merchant_fee_minor === null
            ? null
            : Money::fromMinor($this->merchant_fee_minor, $this->currency);
    }

    /**
     * What actually lands in the merchant's account.
     */
    public function netAmount(): Money
    {
        $amount = $this->amount();
        $fee = $this->merchantFee();

        if (! $fee instanceof Money || $fee->greaterThan($amount)) {
            return $fee instanceof Money ? Money::zero($this->currency) : $amount;
        }

        return $amount->minus($fee);
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
     * Whether this row has been confirmed by an authenticated status check
     * rather than merely by an inbound callback.
     *
     * Business logic that releases value should gate on this, not on `isPaid()`
     * alone.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Deep link back into the InnBucks app for an unfinished InnBucks payment.
     */
    public function innbucksDeepLink(): ?string
    {
        if ($this->innbucks_code === null) {
            return null;
        }

        return sprintf('schinn.wbpycode://innbucks.co.zw?pymInnCode=%s', rawurlencode($this->innbucks_code));
    }

    /**
     * How long this transaction has been open, in seconds.
     */
    public function ageInSeconds(): int
    {
        return (int) ($this->created_at?->diffInSeconds() ?? 0);
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', array_map(
            static fn (TransactionStatus $s) => $s->value,
            array_filter(TransactionStatus::cases(), static fn (TransactionStatus $s) => ! $s->isFinal())
        ));
    }

    /** @param  Builder<self>  $query */
    public function scopePaid(Builder $query): void
    {
        $query->where('status', TransactionStatus::PAID->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeUnverified(Builder $query): void
    {
        $query->whereNull('verified_at');
    }

    /** @param  Builder<self>  $query */
    public function scopeForEnvironment(Builder $query, Environment|string $environment): void
    {
        $query->where('environment', Environment::fromLoose($environment)->value);
    }

    /**
     * Stale open transactions — the reconciliation work list.
     *
     * A wallet push that nobody answered sits PROCESSING forever unless someone
     * goes looking. This scope is what the reconcile command walks.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStale(Builder $query, int $olderThanSeconds = 300): void
    {
        $query->pending()->where('created_at', '<=', now()->subSeconds($olderThanSeconds));
    }
}
