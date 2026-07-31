<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Events;

use AaronKatema\SmilePay\DTO\PaymentRequest;
use AaronKatema\SmilePay\DTO\PaymentResult;
use AaronKatema\SmilePay\Models\SmilePayTransaction;

/**
 * Smile&Pay accepted a payment instruction.
 *
 * Fired once per successful initiation. Useful for analytics and for kicking
 * off a delayed poll job. It is *not* a signal that money moved — listen for
 * PaymentSucceeded before releasing anything of value.
 */
final class PaymentInitiated
{

    public function __construct(
        public readonly PaymentRequest $request,
        public readonly PaymentResult $result,
        public readonly ?SmilePayTransaction $transaction = null,
    ) {}
}
