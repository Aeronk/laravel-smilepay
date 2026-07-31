<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Models;

use AaronKatema\SmilePay\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only record of one inbound callback.
 *
 * Smile&Pay signs nothing, so a callback body is a *claim* about a payment,
 * not evidence of one. This table records the claim, what the authenticated
 * status check said in reply, and whether the two agreed.
 *
 * Rows where `verified` is false and `claimed_status` is PAID are the ones to
 * watch: something told your server a payment succeeded and the gateway
 * disagreed. That is either a bug or an attack, and either way you want to
 * find out from a dashboard rather than from a stock discrepancy.
 *
 * @property int $id
 * @property string $order_reference
 * @property string|null $transaction_reference
 * @property TransactionStatus|null $claimed_status
 * @property TransactionStatus|null $verified_status
 * @property bool $verified
 * @property bool $acted_on
 * @property string|null $source_ip
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $verification_response
 * @property string|null $rejection_reason
 * @property \Illuminate\Support\Carbon $received_at
 */
class SmilePayWebhookEvent extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claimed_status' => TransactionStatus::class,
            'verified_status' => TransactionStatus::class,
            'verified' => 'boolean',
            'acted_on' => 'boolean',
            'payload' => 'array',
            'verification_response' => 'array',
            'received_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? (string) config('smilepay.database.webhook_events_table', 'smilepay_webhook_events');
    }

    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('smilepay.database.connection');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(SmilePayTransaction::class, 'order_reference', 'order_reference');
    }

    /**
     * A callback that claimed success but failed verification.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSuspicious(Builder $query): void
    {
        $query->where('verified', false)
            ->where('claimed_status', TransactionStatus::PAID->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeVerified(Builder $query): void
    {
        $query->where('verified', true);
    }
}
