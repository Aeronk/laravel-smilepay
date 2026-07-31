<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Enums;

/**
 * Payment rails exposed by the Smile&Pay gateway.
 *
 * The enum value is the exact wire token Smile&Pay expects in `paymentMethod`
 * and returns in `paymentOption`, so no translation table is needed. Behaviour
 * that differs per rail — does it need a mobile number, does it need an OTP —
 * is expressed as methods here rather than as conditionals scattered through
 * the gateway, which keeps adding a new rail to a single file.
 */
enum PaymentMethod: string
{
    /** EcoCash mobile money (Econet). Largest wallet in Zimbabwe by volume. */
    case ECOCASH = 'ECOCASH';

    /** OneMoney mobile money (NetOne). */
    case ONEMONEY = 'ONEMONEY';

    /** O'mari wallet. Two-step: requires OTP confirmation. */
    case OMARI = 'OMARI';

    /** InnBucks (Simbisa) wallet. */
    case INNBUCKS = 'INNBUCKS';

    /** ZB's SmileCash / WalletPlus. Two-step: requires OTP confirmation. */
    case WALLETPLUS = 'WALLETPLUS';

    /** Visa / Mastercard, handled on ZB's hosted 3DS page. */
    case CARD = 'CARD';

    /**
     * Wallet rails identify the payer by mobile number, so `mobilePhoneNumber`
     * is required and validated before the call is made.
     */
    public function requiresMsisdn(): bool
    {
        return $this !== self::CARD;
    }

    /**
     * Two-step rails send the customer an SMS OTP that must be collected in
     * your UI and submitted to the confirm endpoint before funds move.
     */
    public function requiresOtp(): bool
    {
        return match ($this) {
            self::WALLETPLUS, self::OMARI => true,
            default => false,
        };
    }

    /**
     * Whether this rail can be driven from your own UI via Express Checkout.
     *
     * Cards can (the MPGS endpoint exists) but generally should not — doing so
     * moves you from PCI SAQ A to SAQ D. That is a commercial decision, not
     * something this enum should make for you, so the warning lives in
     * CardDetails and SmilePay::card() rather than in a hard block here.
     */
    public function supportsExpressCheckout(): bool
    {
        return true;
    }

    /**
     * Cards hand the customer to a hosted page and return via `returnUrl`.
     */
    public function isRedirectBased(): bool
    {
        return $this === self::CARD;
    }

    /**
     * The mobile network that issues this wallet, where the rail is tied to one.
     *
     * Used to warn when a customer's number cannot possibly work with the
     * chosen rail — an Econet number on a OneMoney request is a certain decline.
     *
     * Only the two rails whose operator is unambiguous are mapped. O'mari,
     * InnBucks and SmileCash return null and are treated as network-agnostic:
     * ZB's own sandbox issues O'mari a 073 number, which does not match the
     * operator the rail is usually associated with, and guessing here would
     * produce confident warnings about perfectly valid payments. Confirm the
     * real prefix ranges with your ZB contact before narrowing this.
     */
    public function network(): ?string
    {
        return match ($this) {
            self::ECOCASH => 'Econet',
            self::ONEMONEY => 'NetOne',
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ECOCASH => 'EcoCash',
            self::ONEMONEY => 'OneMoney',
            self::OMARI => "O'mari",
            self::INNBUCKS => 'InnBucks',
            self::WALLETPLUS => 'SmileCash',
            self::CARD => 'Card',
        };
    }

    /**
     * Resolve from loose input, tolerating the aliases merchants actually type.
     */
    public static function fromLoose(self|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalised = strtoupper((string) preg_replace('/[^a-z0-9]/i', '', $value));

        return match ($normalised) {
            'SMILECASH', 'SMILE', 'ZBWALLET', 'WALLET', 'WALLETPLUS' => self::WALLETPLUS,
            'ECOCASH', 'ECO' => self::ECOCASH,
            'ONEMONEY', 'NETONE' => self::ONEMONEY,
            'OMARI', 'OMARIWALLET' => self::OMARI,
            'INNBUCKS', 'INN' => self::INNBUCKS,
            'CARD', 'VISA', 'MASTERCARD', 'VISAMASTERCARD' => self::CARD,
            default => self::from($normalised),
        };
    }

    public static function tryFromLoose(self|string|null $value): ?self
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        try {
            return self::fromLoose($value);
        } catch (\ValueError) {
            return null;
        }
    }

    /**
     * Rails that complete without an OTP step.
     *
     * @return list<self>
     */
    public static function singleStep(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $m) => $m->supportsExpressCheckout() && ! $m->requiresOtp()
        ));
    }

    /**
     * Rails that require an OTP confirmation.
     *
     * @return list<self>
     */
    public static function twoStep(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $m) => $m->requiresOtp()));
    }
}
