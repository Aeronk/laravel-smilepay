<?php

declare(strict_types=1);

use AaronKatema\SmilePay\DTO\Customer;
use AaronKatema\SmilePay\DTO\PaymentRequest;
use AaronKatema\SmilePay\Enums\PaymentMethod;
use AaronKatema\SmilePay\Enums\TransactionStatus;
use AaronKatema\SmilePay\Events\PaymentInitiated;
use AaronKatema\SmilePay\Exceptions\AuthenticationException;
use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;
use AaronKatema\SmilePay\Models\SmilePayTransaction;
use AaronKatema\SmilePay\SmilePay;
use Illuminate\Support\Facades\Event;

it('sends the documented payload to the hosted checkout endpoint', function (): void {
    $this->mockGateway([
        $this->jsonResponse([
            'responseMessage' => 'Transaction initiated successfully',
            'responseCode' => '00',
            'paymentUrl' => 'https://zbnet.zb.co.zw/wallet_sandbox_checkout?reference=xyz123',
            'transactionReference' => 'TXN-789456123',
        ]),
    ]);

    $result = app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-12345', 100.00, 'USD')
            ->withItem('Premium Subscription', '1 Month Premium Access')
            ->withCustomer(Customer::make('0771234567', 'john@example.com', 'John Doe'))
    );

    expect($this->sentPath())->toEndWith('/payments/initiate-transaction');

    expect($this->sentBody())->toMatchArray([
        'orderReference' => 'ORDER-12345',
        'amount' => 100.0,
        // ISO 4217 numeric, not alphabetic — the trap in this API.
        'currencyCode' => '840',
        'itemName' => 'Premium Subscription',
        'itemDescription' => '1 Month Premium Access',
        'returnUrl' => 'https://merchant.test/return',
        'resultUrl' => 'https://merchant.test/callback',
        'firstName' => 'John',
        'lastName' => 'Doe',
        'email' => 'john@example.com',
        'mobilePhoneNumber' => '0771234567',
    ]);

    expect($result->accepted)->toBeTrue()
        ->and($result->nextAction())->toBe('redirect')
        ->and($result->paymentUrl)->toContain('wallet_sandbox_checkout')
        ->and($result->transactionReference)->toBe('TXN-789456123')
        ->and($result->status)->toBe(TransactionStatus::PENDING);
});

it('authenticates with both required headers', function (): void {
    $this->mockGateway([$this->jsonResponse(['responseCode' => '00'])]);

    app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-1', 5.00, 'USD')->withItem('Widget')
    );

    expect($this->sentHeader('x-api-key'))->toBe('test_key')
        ->and($this->sentHeader('x-api-secret'))->toBe('test_secret');
});

it('sends ZWG as numeric 924', function (): void {
    $this->mockGateway([$this->jsonResponse(['responseCode' => '00'])]);

    app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-Z', 250.00, 'ZWG')->withItem('Widget')
    );

    expect($this->sentBody()['currencyCode'])->toBe('924');
});

it('writes the transaction row before calling the gateway', function (): void {
    // Proves the ordering that makes a timeout recoverable: if the call below
    // had failed, the row would still exist for reconciliation to find.
    $this->mockGateway([$this->jsonResponse(['responseCode' => '00', 'transactionReference' => 'TXN-1'])]);

    app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-R', 12.50, 'USD')
            ->withItem('Widget')
            ->withMetadata(['tenant_id' => 7])
    );

    $transaction = SmilePayTransaction::query()->where('order_reference', 'ORDER-R')->firstOrFail();

    expect($transaction->amount_minor)->toBe(1250)
        ->and($transaction->currency->value)->toBe('USD')
        ->and($transaction->metadata)->toBe(['tenant_id' => 7])
        ->and($transaction->initiated_at)->not->toBeNull();
});

it('keeps the transaction open when the gateway call fails', function (): void {
    // A 500 does NOT prove the transaction was not created — the customer may
    // already have been prompted. Marking it failed here would hide a real
    // payment from reconciliation.
    $this->mockGateway([
        $this->jsonResponse(['message' => 'Server error'], 500),
        $this->jsonResponse(['message' => 'Server error'], 500),
        $this->jsonResponse(['message' => 'Server error'], 500),
    ]);

    expect(fn () => app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-F', 10.00, 'USD')->withItem('Widget')
    ))->toThrow(AaronKatema\SmilePay\Exceptions\GatewayUnavailableException::class);

    $transaction = SmilePayTransaction::query()->where('order_reference', 'ORDER-F')->firstOrFail();

    expect($transaction->status->isFinal())->toBeFalse()
        ->and($transaction->last_error)->not->toBeNull();
});

it('does not retry a payment initiation', function (): void {
    // Retrying could charge the customer twice. Exactly one request must leave.
    $this->mockGateway([
        $this->jsonResponse(['message' => 'Bad gateway'], 502),
        $this->jsonResponse(['responseCode' => '00']),
    ]);

    try {
        app(SmilePay::class)->checkout(
            PaymentRequest::make('ORDER-N', 10.00, 'USD')->withItem('Widget')
        );
    } catch (Throwable) {
        // expected
    }

    expect($this->recorded)->toHaveCount(1);
});

it('retries an idempotent status check', function (): void {
    $this->mockGateway([
        $this->jsonResponse(['message' => 'Service unavailable'], 503),
        $this->jsonResponse(['orderReference' => 'ORDER-S', 'status' => 'PAID']),
    ]);

    $snapshot = app(SmilePay::class)->status('ORDER-S');

    expect($snapshot->status)->toBe(TransactionStatus::PAID)
        ->and($this->recorded)->toHaveCount(2);
});

it('translates a 401 into an authentication exception', function (): void {
    $this->mockGateway([$this->jsonResponse(['message' => 'Invalid credentials'], 401)]);

    expect(fn () => app(SmilePay::class)->status('ORDER-1'))
        ->toThrow(AuthenticationException::class, 'Invalid credentials');
});

it('treats a non-00 response code as a failure despite HTTP 200', function (): void {
    // The single most common way to build a payment integration that ships
    // goods for free.
    $this->mockGateway([
        $this->jsonResponse([
            'responseCode' => '51',
            'responseMessage' => 'Insufficient funds',
        ], 200),
    ]);

    $result = app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-D', 10.00, 'USD')->withItem('Widget')
    );

    expect($result->accepted)->toBeFalse()
        ->and($result->failed())->toBeTrue()
        ->and($result->status)->toBe(TransactionStatus::FAILED)
        ->and($result->message)->toBe('Insufficient funds')
        ->and($result->nextAction())->toBe('failed');
});

it('fires PaymentInitiated only on an accepted initiation', function (): void {
    Event::fake([PaymentInitiated::class]);

    $this->mockGateway([$this->jsonResponse(['responseCode' => '05'])]);

    app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-E', 10.00, 'USD')->withItem('Widget')
    );

    Event::assertNotDispatched(PaymentInitiated::class);
});

it('rejects a request with no result URL', function (): void {
    config()->set('smilepay.defaults.result_url', null);
    $this->app->forgetInstance(AaronKatema\SmilePay\Support\Config::class);
    $this->app->forgetInstance(SmilePay::class);

    expect(fn () => app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-1', 10.00, 'USD')->withItem('Widget')
    ))->toThrow(InvalidPaymentRequestException::class, 'resultUrl');
});

it('rejects a zero amount before any network call', function (): void {
    $this->mockGateway([]);

    expect(fn () => app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-1', 0, 'USD')->withItem('Widget')
    ))->toThrow(InvalidPaymentRequestException::class);

    expect($this->recorded)->toBeEmpty();
});

it('cancels a pending transaction', function (): void {
    $this->mockGateway([
        $this->jsonResponse(['responseCode' => '00', 'status' => 'CANCELLED']),
    ]);

    $snapshot = app(SmilePay::class)->cancel('ORDER-C');

    expect($this->sentPath())->toEndWith('/payments/cancel/ORDER-C')
        ->and($snapshot->status)->toBe(TransactionStatus::CANCELLED);
});
