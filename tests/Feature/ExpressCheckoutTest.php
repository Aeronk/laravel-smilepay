<?php

declare(strict_types=1);

use AaronKatema\SmilePay\DTO\CardDetails;
use AaronKatema\SmilePay\DTO\PaymentRequest;
use AaronKatema\SmilePay\Enums\PaymentMethod;
use AaronKatema\SmilePay\Enums\TransactionStatus;
use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;
use AaronKatema\SmilePay\SmilePay;

it('posts to the ecocash endpoint with the ecocashMobile field', function (): void {
    $this->mockGateway([$this->jsonResponse(['responseCode' => '00', 'transactionReference' => 'TXN-1'])]);

    $result = app(SmilePay::class)->ecocash(
        PaymentRequest::make('ORDER-1', 100.00, 'USD')
            ->withItem('Product Name', 'Product Description')
            ->withMsisdn('0771234567')
    );

    expect($this->sentPath())->toEndWith('/payments/express-checkout/ecocash');

    expect($this->sentBody())->toMatchArray([
        'orderReference' => 'ORDER-1',
        'amount' => 100.0,
        'currencyCode' => '840',
        'resultUrl' => 'https://merchant.test/callback',
        'ecocashMobile' => '0771234567',
    ]);

    // Express rails identify themselves by endpoint; no returnUrl is sent.
    expect($this->sentBody())->not->toHaveKey('returnUrl')
        ->and($this->sentBody())->not->toHaveKey('paymentMethod');

    expect($result->nextAction())->toBe('poll');
});

it('uses each rail its own mobile field name', function (string $method, string $field, string $path): void {
    $this->mockGateway([$this->jsonResponse(['responseCode' => '00'])]);

    app(SmilePay::class)->express(
        PaymentRequest::make('ORDER-1', 10.00, 'USD')
            ->withItem('Widget')
            ->withMethod($method)
            ->withMsisdn($method === 'OMARI' ? '0731234567' : '0771234567')
    );

    expect($this->sentPath())->toEndWith($path)
        ->and($this->sentBody())->toHaveKey($field);
})->with([
    ['ECOCASH', 'ecocashMobile', '/payments/express-checkout/ecocash'],
    ['ONEMONEY', 'oneMoneyMobile', '/payments/express-checkout/onemoney'],
    ['WALLETPLUS', 'zbWalletMobile', '/payments/express-checkout/zb-payment'],
    ['OMARI', 'omariMobile', '/payments/express-checkout/omari'],
]);

it('returns an InnBucks code and a deep link', function (): void {
    $this->mockGateway([
        $this->jsonResponse(['responseCode' => '00', 'innbucksPaymentCode' => '701564']),
    ]);

    $result = app(SmilePay::class)->innbucks(
        PaymentRequest::make('ORDER-1', 100.00, 'USD')->withItem('Widget')
    );

    expect($this->sentPath())->toEndWith('/payments/express-checkout/innbucks')
        ->and($result->nextAction())->toBe('innbucks_code')
        ->and($result->innbucksPaymentCode)->toBe('701564')
        ->and($result->innbucksDeepLink())
        ->toBe('schinn.wbpycode://innbucks.co.zw?pymInnCode=701564');
});

it('marks a two-step rail as awaiting an OTP', function (): void {
    $this->mockGateway([
        $this->jsonResponse(['responseCode' => '00', 'transactionReference' => 'TXN-OTP-1']),
    ]);

    $result = app(SmilePay::class)->smileCash(
        PaymentRequest::make('ORDER-1', 25.00, 'USD')->withItem('Widget')->withMsisdn('0711111111')
    );

    expect($result->status)->toBe(TransactionStatus::AWAITING_OTP)
        ->and($result->nextAction())->toBe('otp')
        ->and($result->transactionReference)->toBe('TXN-OTP-1');
});

it('confirms a SmileCash OTP with the transaction reference, not the order reference', function (): void {
    // ZB's own docs flag this as the trap. Sending orderReference here fails.
    $this->mockGateway([
        $this->jsonResponse(['responseCode' => '00', 'status' => 'PAID']),
    ]);

    app(SmilePay::class)->confirmOtp('TXN-OTP-1', '000000', PaymentMethod::WALLETPLUS);

    expect($this->sentPath())->toEndWith('/payments/express-checkout/zb-payment/confirmation')
        ->and($this->sentBody())->toBe([
            'transactionReference' => 'TXN-OTP-1',
            'otp' => '000000',
        ]);
});

it("echoes the mobile number back on an O'mari OTP confirmation", function (): void {
    // O'mari requires it; SmileCash does not. Getting this wrong yields an
    // unhelpful validation error from the gateway.
    $this->mockGateway([$this->jsonResponse(['responseCode' => '00'])]);

    app(SmilePay::class)->confirmOtp('TXN-OTP-2', '000000', PaymentMethod::OMARI, '0731234567');

    expect($this->sentPath())->toEndWith('/payments/express-checkout/omari/confirmation')
        ->and($this->sentBody())->toMatchArray([
            'transactionReference' => 'TXN-OTP-2',
            'otp' => '000000',
            'omariMobile' => '0731234567',
        ]);
});

it("refuses an O'mari confirmation without a mobile number", function (): void {
    $this->mockGateway([]);

    expect(fn () => app(SmilePay::class)->confirmOtp('TXN-1', '000000', PaymentMethod::OMARI))
        ->toThrow(InvalidPaymentRequestException::class);

    expect($this->recorded)->toBeEmpty();
});

it('rejects an OTP confirmation for a single-step rail', function (): void {
    expect(fn () => app(SmilePay::class)->confirmOtp('TXN-1', '000000', PaymentMethod::ECOCASH))
        ->toThrow(InvalidPaymentRequestException::class, 'does not use OTP');
});

it('parses a 3DS challenge from the card endpoint', function (): void {
    $this->mockGateway([
        $this->jsonResponse([
            'responseMessage' => 'Transaction initiated successfully',
            'responseCode' => '00',
            'status' => 'PENDING_3DS',
            'transactionReference' => 'TXN-123456789',
            'gatewayRecommendation' => 'PROCEED',
            'authenticationStatus' => 'AUTHENTICATION_REQUIRED',
            'redirectHtml' => '<html><form id="3ds"></form></html>',
            'customizedHtml' => [
                '3ds2' => [
                    'acsUrl' => 'https://acs.example.com',
                    'cReq' => 'eyJtZXNzYWdlVHlwZSI6IkNSZXEifQ==',
                ],
            ],
        ]),
    ]);

    $result = app(SmilePay::class)->card(
        PaymentRequest::make('ORDER-1', 10.00, 'USD')->withItem('Widget'),
        CardDetails::make('5123450000000008', '01', '39', '100')
    );

    expect($this->sentPath())->toEndWith('/payments/express-checkout/mpgs')
        ->and($this->sentBody())->toMatchArray([
            'pan' => '5123450000000008',
            'expMonth' => '01',
            'expYear' => '39',
            'securityCode' => '100',
            'paymentMethod' => 'CARD',
        ]);

    expect($result->needs3ds())->toBeTrue()
        ->and($result->status)->toBe(TransactionStatus::AWAITING_3DS)
        ->and($result->challenge->acsUrl)->toBe('https://acs.example.com')
        ->and($result->challenge->hasStructuredChallenge())->toBeTrue();

    // The safe form posts to the ACS without executing ZB's script in our origin.
    expect($result->challenge->toSafeHtml())
        ->toContain('action="https://acs.example.com"')
        ->toContain('name="creq"');
});

it('refuses card details on a wallet rail', function (): void {
    $this->mockGateway([]);

    expect(fn () => app(SmilePay::class)->express(
        PaymentRequest::make('ORDER-1', 10.00, 'USD')
            ->withItem('Widget')
            ->withMethod(PaymentMethod::ECOCASH)
            ->withMsisdn('0771234567'),
        CardDetails::make('5123450000000008', '01', '39', '100')
    ))->toThrow(InvalidPaymentRequestException::class);
});

it('requires a mobile number for wallet rails', function (): void {
    $this->mockGateway([]);

    expect(fn () => app(SmilePay::class)->ecocash(
        PaymentRequest::make('ORDER-1', 10.00, 'USD')->withItem('Widget')
    ))->toThrow(InvalidPaymentRequestException::class, 'mobile number');

    expect($this->recorded)->toBeEmpty();
});
