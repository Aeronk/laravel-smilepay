<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Events;

use AaronKatema\SmilePay\DTO\TransactionSnapshot;
use AaronKatema\SmilePay\Models\SmilePayTransaction;

/**
 * A pending payment was cancelled — by the merchant through the cancel
 * endpoint, or by the customer abandoning the hosted page.
 */
final class PaymentCancelled
{

    public function __construct(
        public readonly TransactionSnapshot $snapshot,
        public readonly ?SmilePayTransaction $transaction = null,
    ) {}
}
