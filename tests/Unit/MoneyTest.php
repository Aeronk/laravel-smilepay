<?php

declare(strict_types=1);

use AaronKatema\SmilePay\DTO\Money;
use AaronKatema\SmilePay\Enums\Currency;
use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;

it('stores amounts in integer minor units', function (): void {
    expect(Money::fromDecimal('10.50', 'USD')->minor)->toBe(1050)
        ->and(Money::fromDecimal(100, 'USD')->minor)->toBe(10000)
        ->and(Money::fromMinor(1250, 'USD')->toDecimal())->toBe('12.50');
});

it('survives float arithmetic that would break a naive implementation', function (): void {
    // 0.1 + 0.2 !== 0.3 in binary floating point. Merchants notice.
    $total = Money::fromDecimal('0.10', 'USD')
        ->plus(Money::fromDecimal('0.20', 'USD'));

    expect($total->toDecimal())->toBe('0.30')
        ->and($total->minor)->toBe(30);

    $sum = Money::zero('USD');

    foreach (range(1, 100) as $ignored) {
        $sum = $sum->plus(Money::fromDecimal('0.07', 'USD'));
    }

    expect($sum->toDecimal())->toBe('7.00');
});

it('rounds half up at the currency precision', function (): void {
    expect(Money::fromDecimal('10.005', 'USD')->toDecimal())->toBe('10.01')
        ->and(Money::fromDecimal('10.004', 'USD')->toDecimal())->toBe('10.00');
});

it('refuses to mix currencies', function (): void {
    expect(fn () => Money::fromDecimal('1.00', 'USD')->plus(Money::fromDecimal('1.00', 'ZWG')))
        ->toThrow(InvalidPaymentRequestException::class, 'different currencies');
});

it('refuses a negative amount', function (): void {
    expect(fn () => Money::fromMinor(-1, 'USD'))
        ->toThrow(InvalidPaymentRequestException::class);
});

it('refuses a non-numeric amount', function (): void {
    expect(fn () => Money::fromDecimal('ten dollars', 'USD'))
        ->toThrow(InvalidPaymentRequestException::class);
});

it('formats for humans', function (): void {
    expect(Money::fromDecimal('1234.5', 'USD')->format())->toBe('$1234.50')
        ->and(Money::fromDecimal('99', 'ZWG')->format())->toBe('ZiG99.00');
});

it('maps currencies to their ISO numeric codes', function (): void {
    expect(Currency::USD->numericCode())->toBe('840')
        ->and(Currency::ZWG->numericCode())->toBe('924')
        ->and(Currency::fromLoose('840'))->toBe(Currency::USD)
        ->and(Currency::fromLoose('924'))->toBe(Currency::ZWG)
        ->and(Currency::fromLoose('usd'))->toBe(Currency::USD);
});

it('rejects a currency Smile&Pay does not settle', function (): void {
    expect(fn () => Currency::fromLoose('ZAR'))
        ->toThrow(InvalidPaymentRequestException::class, 'not supported');
});
