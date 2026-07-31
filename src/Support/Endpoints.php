<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Support;

use AaronKatema\SmilePay\Enums\PaymentMethod;
use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;

/**
 * Every Smile&Pay path and rail-specific field name, in one place.
 *
 * The API is not uniform: each express rail has its own path *and* its own name
 * for the customer's mobile number — `ecocashMobile`, `oneMoneyMobile`,
 * `zbWalletMobile`, `omariMobile`. Centralising that here means the gateway
 * code has no per-rail branching, and adding a rail is one entry rather than a
 * search through conditionals.
 */
final class Endpoints
{
    /** Standard hosted checkout. */
    public const INITIATE = 'payments/initiate-transaction';

    /** Express card payment via Mastercard Payment Gateway Services. */
    public const EXPRESS_CARD = 'payments/express-checkout/mpgs';

    /**
     * Express initiation path per rail.
     *
     * @var array<string, string>
     */
    private const EXPRESS = [
        PaymentMethod::ECOCASH->value => 'payments/express-checkout/ecocash',
        PaymentMethod::ONEMONEY->value => 'payments/express-checkout/onemoney',
        PaymentMethod::INNBUCKS->value => 'payments/express-checkout/innbucks',
        PaymentMethod::WALLETPLUS->value => 'payments/express-checkout/zb-payment',
        PaymentMethod::OMARI->value => 'payments/express-checkout/omari',
        PaymentMethod::CARD->value => self::EXPRESS_CARD,
    ];

    /**
     * OTP confirmation path, for the two-step rails only.
     *
     * @var array<string, string>
     */
    private const CONFIRM = [
        PaymentMethod::WALLETPLUS->value => 'payments/express-checkout/zb-payment/confirmation',
        PaymentMethod::OMARI->value => 'payments/express-checkout/omari/confirmation',
    ];

    /**
     * The JSON key each rail uses for the customer's mobile number.
     *
     * @var array<string, string>
     */
    private const MOBILE_FIELD = [
        PaymentMethod::ECOCASH->value => 'ecocashMobile',
        PaymentMethod::ONEMONEY->value => 'oneMoneyMobile',
        PaymentMethod::WALLETPLUS->value => 'zbWalletMobile',
        PaymentMethod::OMARI->value => 'omariMobile',
    ];

    /**
     * Rails whose OTP confirmation must also echo the mobile number back.
     *
     * O'mari requires it; SmileCash does not. Getting this wrong produces a
     * validation error from the gateway with no useful message, so it is
     * encoded rather than remembered.
     *
     * @var array<string, bool>
     */
    private const CONFIRM_NEEDS_MOBILE = [
        PaymentMethod::OMARI->value => true,
        PaymentMethod::WALLETPLUS->value => false,
    ];

    public static function express(PaymentMethod $method): string
    {
        $path = self::EXPRESS[$method->value] ?? null;

        if ($path === null) {
            throw InvalidPaymentRequestException::expressCheckoutNotSupported($method->value);
        }

        return $path;
    }

    public static function confirm(PaymentMethod $method): string
    {
        $path = self::CONFIRM[$method->value] ?? null;

        if ($path === null) {
            throw InvalidPaymentRequestException::otpNotSupported($method->value);
        }

        return $path;
    }

    /**
     * Null for rails that take no mobile number (InnBucks, card).
     */
    public static function mobileField(PaymentMethod $method): ?string
    {
        return self::MOBILE_FIELD[$method->value] ?? null;
    }

    public static function confirmNeedsMobile(PaymentMethod $method): bool
    {
        return self::CONFIRM_NEEDS_MOBILE[$method->value] ?? false;
    }

    /**
     * Status check for a merchant order reference.
     */
    public static function status(string $orderReference): string
    {
        return sprintf('payments/transaction/%s/status/check', rawurlencode($orderReference));
    }

    /**
     * Cancel a pending transaction.
     */
    public static function cancel(string $orderReference): string
    {
        return sprintf('payments/cancel/%s', rawurlencode($orderReference));
    }
}
