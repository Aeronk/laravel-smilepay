<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Exceptions;

/**
 * The request was rejected locally, before any network call was made.
 *
 * Failing here is strictly cheaper than failing at the gateway: no partially
 * created transaction, nothing to reconcile, and a precise message for the
 * developer rather than a generic gateway rejection.
 */
final class InvalidPaymentRequestException extends SmilePayException
{
    public static function negativeAmount(): self
    {
        return new self('A payment amount cannot be negative.');
    }

    public static function zeroAmount(): self
    {
        return new self('A payment amount must be greater than zero.');
    }

    public static function nonNumericAmount(string $value): self
    {
        return new self(sprintf('Payment amount [%s] is not numeric.', $value));
    }

    public static function missingMethodForExpress(): self
    {
        return new self(
            'Express Checkout requires an explicit payment method — each rail has its own '
            .'endpoint. Call withMethod(), or use checkout() to let the customer choose on '
            ."ZB's hosted page."
        );
    }

    public static function invalidCard(string $reason): self
    {
        // The reason is always structural ("failed the Luhn check"), never the
        // value itself — card data must not reach an exception message, which
        // is the one string guaranteed to end up in a log.
        return new self(sprintf('Invalid card details: %s.', $reason));
    }

    public static function cardExpired(): self
    {
        return new self('The card has expired.');
    }

    public static function missingCard(): self
    {
        return new self(
            'Card payments through Express Checkout require card details. '
            .'Pass a CardDetails object, or use checkout() to keep card data off your servers.'
        );
    }

    public static function unsupportedCurrency(string $value): self
    {
        return new self(sprintf(
            'Currency [%s] is not supported by Smile&Pay. Use USD (840) or ZWG (924).',
            $value
        ));
    }

    public static function missingOtp(): self
    {
        return new self(
            'This payment method requires an OTP. Collect the code the customer '
            .'received by SMS and pass it to confirmOtp().'
        );
    }

    public static function otpNotSupported(string $method): self
    {
        return new self(sprintf(
            'The [%s] payment method does not use OTP confirmation. '
            .'Only SmileCash (WALLETPLUS) and O\'mari are two-step rails.',
            $method
        ));
    }

    public static function expressCheckoutNotSupported(string $method): self
    {
        return new self(sprintf(
            'The [%s] payment method cannot be used with Express Checkout. '
            .'Card payments must go through the hosted checkout page for PCI and 3DS compliance.',
            $method
        ));
    }

    public static function missingItemDetails(): self
    {
        return new self(
            'Smile&Pay requires both itemName and itemDescription on hosted checkout. '
            .'Set them with withItem(), or configure smilepay.defaults.item_name.'
        );
    }

    public static function missingReturnUrl(): self
    {
        return new self(
            'A returnUrl is required for hosted checkout. Pass one with withReturnUrl(), '
            .'or set SMILEPAY_RETURN_URL in your .env file.'
        );
    }

    public static function missingResultUrl(): self
    {
        return new self(
            'A resultUrl (webhook) is required by Smile&Pay. Pass one with withWebhookUrl(), '
            .'or leave it unset to use the package\'s built-in callback route.'
        );
    }

    public static function currencyMismatch(string $left, string $right): self
    {
        return new self(sprintf(
            'Cannot combine amounts in different currencies: %s and %s.',
            $left,
            $right
        ));
    }

    public static function missingMsisdn(string $method): self
    {
        return new self(sprintf(
            'The [%s] payment method requires a customer mobile number (msisdn).',
            $method
        ));
    }

    public static function invalidMsisdn(string $msisdn): self
    {
        return new self(sprintf(
            'The mobile number [%s] is not a valid Zimbabwean MSISDN. '
            .'Expected a 09xxxxxxxx, 2637xxxxxxxx or +2637xxxxxxxx format.',
            $msisdn
        ));
    }

    public static function invalidEmail(string $email): self
    {
        return new self(sprintf('The customer email [%s] is not a valid address.', $email));
    }

    public static function missingReference(): self
    {
        return new self('A merchant reference is required and cannot be blank.');
    }

    public static function referenceTooLong(int $max, int $given): self
    {
        return new self(sprintf(
            'Merchant reference exceeds the %d character limit (%d given).',
            $max,
            $given
        ));
    }

    public static function refundExceedsCaptured(string $refund, string $captured): self
    {
        return new self(sprintf(
            'Refund of %s exceeds the captured amount of %s.',
            $refund,
            $captured
        ));
    }

    public static function invalidUrl(string $field, string $value): self
    {
        return new self(sprintf('The [%s] value [%s] is not a valid absolute URL.', $field, $value));
    }
}
