<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Events;

use AaronKatema\SmilePay\DTO\TransactionSnapshot;
use AaronKatema\SmilePay\Enums\TransactionStatus;
use AaronKatema\SmilePay\Models\SmilePayTransaction;

/**
 * Any verified transition, including non-terminal ones.
 *
 * Fired alongside the specific events, for listeners that want the whole
 * lifecycle — a live checkout UI over websockets, or an audit stream.
 */
final class PaymentStatusChanged
{

    public function __construct(
        public readonly TransactionSnapshot $snapshot,
        public readonly TransactionStatus $from,
        public readonly TransactionStatus $to,
        public readonly ?SmilePayTransaction $transaction = null,
    ) {}

    public function becameFinal(): bool
    {
        return ! $this->from->isFinal() && $this->to->isFinal();
    }
}
