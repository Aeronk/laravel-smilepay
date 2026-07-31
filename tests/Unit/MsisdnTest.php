<?php

declare(strict_types=1);

use AaronKatema\SmilePay\Enums\PaymentMethod;
use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;
use AaronKatema\SmilePay\Support\Msisdn;

it('normalises every format a customer might type', function (string $input): void {
    expect(Msisdn::parse($input)->international())->toBe('263771234567');
})->with([
    '0771234567',
    '771234567',
    '263771234567',
    '+263771234567',
    '00263771234567',
    '077 123 4567',
    '+263 77 123 4567',
    '077-123-4567',
]);

it('renders each format on demand', function (): void {
    $msisdn = Msisdn::parse('0771234567');

    expect($msisdn->national())->toBe('0771234567')
        ->and($msisdn->e164())->toBe('+263771234567')
        ->and($msisdn->international())->toBe('263771234567');
});

it('identifies the network', function (string $number, string $network): void {
    expect(Msisdn::parse($number)->network())->toBe($network);
})->with([
    ['0771234567', 'Econet'],
    ['0781234567', 'Econet'],
    ['0711234567', 'NetOne'],
    ['0731234567', 'Telecel'],
]);

it('rejects numbers that are not Zimbabwean mobiles', function (string $input): void {
    expect(fn () => Msisdn::parse($input))->toThrow(InvalidPaymentRequestException::class);
})->with([
    '077123456',      // too short
    '07712345678',    // too long
    '0421234567',     // landline prefix
    '0791234567',     // unassigned prefix
    'not a number',
    '',
]);

it('flags a wallet and network mismatch', function (): void {
    // An Econet number on a OneMoney request is a certain decline. Catching it
    // locally saves the customer a confusing failure.
    expect(Msisdn::parse('0771234567')->matchesMethod(PaymentMethod::ECOCASH))->toBeTrue()
        ->and(Msisdn::parse('0771234567')->matchesMethod(PaymentMethod::ONEMONEY))->toBeFalse()
        ->and(Msisdn::parse('0711234567')->matchesMethod(PaymentMethod::ONEMONEY))->toBeTrue()
        // Rails whose operator the package does not claim to know never warn.
        ->and(Msisdn::parse('0771234567')->matchesMethod(PaymentMethod::INNBUCKS))->toBeTrue()
        ->and(Msisdn::parse('0731234567')->matchesMethod(PaymentMethod::OMARI))->toBeTrue();
});

it('masks for logging', function (): void {
    expect(Msisdn::parse('0771234567')->masked())->toBe('26377****567');
});
