<?php

declare(strict_types=1);

use AaronKatema\SmilePay\Support\Redactor;

it('strips secrets from anything headed for a log', function (): void {
    $clean = Redactor::scrub([
        'x-api-secret' => 'live_secret_value',
        'apiKey' => 'live_key_value',
        'pan' => '5123450000000008',
        'securityCode' => '100',
        'otp' => '000000',
        'amount' => 100.00,
    ]);

    expect($clean['x-api-secret'])->toBe('[redacted]')
        ->and($clean['apiKey'])->toBe('[redacted]')
        ->and($clean['pan'])->toBe('[redacted]')
        ->and($clean['securityCode'])->toBe('[redacted]')
        ->and($clean['otp'])->toBe('[redacted]')
        // Non-sensitive fields survive, or the log would be useless.
        ->and($clean['amount'])->toBe(100.00);
});

it('masks contact details rather than removing them', function (): void {
    $clean = Redactor::scrub([
        'mobilePhoneNumber' => '0771234567',
        'email' => 'aaron@bint.co.zw',
    ]);

    expect($clean['mobilePhoneNumber'])->not->toBe('0771234567')
        ->and($clean['mobilePhoneNumber'])->toEndWith('567')
        ->and($clean['email'])->toBe('a****@bint.co.zw');
});

it('scrubs nested structures', function (): void {
    $clean = Redactor::scrub(['payment' => ['card' => ['pan' => '5123450000000008']]]);

    expect($clean['payment']['card']['pan'])->toBe('[redacted]');
});

it('does not recurse without bound', function (): void {
    $deep = ['v' => 1];

    for ($i = 0; $i < 40; $i++) {
        $deep = ['nested' => $deep];
    }

    expect(fn () => Redactor::scrub($deep))->not->toThrow(Throwable::class);
});

it('masks a credential for a diagnostic line', function (): void {
    expect(Redactor::maskCredential('sk_live_abcdef123456'))->toBe('sk_l****3456')
        ->and(Redactor::maskCredential('short'))->toBe('*****')
        ->and(Redactor::maskCredential(null))->toBe('[redacted]');
});
