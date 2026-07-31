<?php

declare(strict_types=1);

use AaronKatema\SmilePay\DTO\CardDetails;
use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;

it('accepts the Luhn-valid sandbox success card', function (): void {
    expect(CardDetails::make('5123450000000008', '01', '39', '100')->last4())->toBe('0008');
});

it("accepts ZB's failure-path test cards when Luhn is relaxed", function (string $pan): void {
    // ZB's "system error" and "declined" test PANs do not satisfy Luhn, so the
    // failure paths are untestable with the check enforced. Documented on
    // CardDetails::make() and worth confirming with ZB.
    expect(fn () => CardDetails::make($pan, '01', '39', '100'))
        ->toThrow(InvalidPaymentRequestException::class, 'Luhn');

    expect(CardDetails::make($pan, '01', '39', '100', strictLuhn: false)->last4())
        ->toHaveLength(4);
})->with([
    '5123450000000002',
    '5123450000000010',
]);

it('rejects a card that fails the Luhn check', function (): void {
    expect(fn () => CardDetails::make('5123450000000009', '01', '39', '100'))
        ->toThrow(InvalidPaymentRequestException::class, 'Luhn');
});

it('parses an MM/YY expiry', function (): void {
    $card = CardDetails::fromExpiryString('5123450000000008', '01/39', '100');

    expect($card->expMonth)->toBe('01')->and($card->expYear)->toBe('39');
});

it('narrows a four-digit year', function (): void {
    expect(CardDetails::make('5123450000000008', '1', '2039', '100')->expYear)->toBe('39');
});

it('detects an expired card locally', function (): void {
    $card = CardDetails::make('5123450000000008', '01', '20', '100');

    expect($card->isExpired(nowYear: 26, nowMonth: 7))->toBeTrue()
        ->and(CardDetails::make('5123450000000008', '01', '39', '100')->isExpired(26, 7))->toBeFalse();
});

it('never leaks the PAN through debug or serialisation', function (): void {
    $card = CardDetails::make('5123450000000008', '01', '39', '100');

    $debug = print_r($card, true);
    $json = json_encode($card->__serialize());

    expect($debug)->not->toContain('5123450000000008')
        ->and($json)->not->toContain('5123450000000008')
        ->and($json)->not->toContain('100')
        ->and($card->describe())->toBe('Mastercard ****0008');
});

it('refuses to be unserialised', function (): void {
    $card = CardDetails::make('5123450000000008', '01', '39', '100');

    expect(fn () => $card->__unserialize(['card' => 'x']))
        ->toThrow(InvalidPaymentRequestException::class);
});

it('exposes the PAN only through the gateway payload', function (): void {
    $payload = CardDetails::make('5123450000000008', '01', '39', '100')->toGatewayPayload();

    expect($payload)->toBe([
        'pan' => '5123450000000008',
        'expMonth' => '01',
        'expYear' => '39',
        'securityCode' => '100',
    ]);
});
