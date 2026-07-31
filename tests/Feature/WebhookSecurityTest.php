<?php

declare(strict_types=1);

use AaronKatema\SmilePay\DTO\PaymentRequest;
use AaronKatema\SmilePay\Enums\TransactionStatus;
use AaronKatema\SmilePay\Events\PaymentSucceeded;
use AaronKatema\SmilePay\Events\SuspiciousCallbackDetected;
use AaronKatema\SmilePay\Models\SmilePayTransaction;
use AaronKatema\SmilePay\Models\SmilePayWebhookEvent;
use AaronKatema\SmilePay\SmilePay;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| The tests that matter most
|--------------------------------------------------------------------------
|
| Smile&Pay callbacks are unsigned. Every guarantee this package makes about
| not shipping goods for free rests on the behaviour asserted below, so these
| tests are the ones to keep green above all others.
|
*/

it('does not mark an order paid on an unsigned callback alone', function (): void {
    Event::fake([PaymentSucceeded::class, SuspiciousCallbackDetected::class]);

    // A real, pending transaction.
    $this->mockGateway([
        $this->jsonResponse([
            'responseCode' => '00',
            'responseMessage' => 'Transaction initiated successfully',
            'paymentUrl' => 'https://zbnet.zb.co.zw/checkout?reference=abc',
            'transactionReference' => 'TXN-1',
        ]),
        // The attacker's claim is checked against this — the gateway's truth.
        $this->jsonResponse([
            'orderReference' => 'ORDER-1',
            'status' => 'PENDING',
            'amount' => 100.00,
            'currency' => 'USD',
        ]),
    ]);

    app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-1', 100.00, 'USD')->withItem('Widget')
    );

    // Forged callback: anyone who knows the URL can send this.
    $response = $this->postJson(route('smilepay.callback'), [
        'orderReference' => 'ORDER-1',
        'status' => 'PAID',
        'amount' => 100.00,
        'currency' => 'USD',
    ]);

    $response->assertOk();

    $transaction = SmilePayTransaction::query()->where('order_reference', 'ORDER-1')->first();

    expect($transaction->status)->not->toBe(TransactionStatus::PAID)
        ->and($transaction->isPaid())->toBeFalse();

    Event::assertNotDispatched(PaymentSucceeded::class);
    Event::assertDispatched(SuspiciousCallbackDetected::class);
});

it('records a forged callback for investigation rather than dropping it', function (): void {
    $this->mockGateway([
        $this->jsonResponse(['responseCode' => '00', 'transactionReference' => 'TXN-1']),
        $this->jsonResponse(['orderReference' => 'ORDER-2', 'status' => 'PENDING']),
    ]);

    app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-2', 50.00, 'USD')->withItem('Widget')
    );

    $this->postJson(route('smilepay.callback'), [
        'orderReference' => 'ORDER-2',
        'status' => 'PAID',
    ]);

    $event = SmilePayWebhookEvent::query()->where('order_reference', 'ORDER-2')->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and($event->verified)->toBeFalse()
        ->and($event->acted_on)->toBeFalse()
        ->and($event->claimed_status)->toBe(TransactionStatus::PAID)
        ->and($event->verified_status)->toBe(TransactionStatus::PENDING)
        ->and($event->rejection_reason)->toContain('claimed PAID');

    expect(SmilePayWebhookEvent::query()->suspicious()->count())->toBe(1);
});

it('marks an order paid when the gateway confirms it', function (): void {
    Event::fake([PaymentSucceeded::class]);

    $this->mockGateway([
        $this->jsonResponse(['responseCode' => '00', 'transactionReference' => 'TXN-9']),
        $this->jsonResponse([
            'orderReference' => 'ORDER-3',
            'reference' => 'TXN-9',
            'status' => 'PAID',
            'amount' => 100.00,
            'currency' => 'USD',
            'paymentOption' => 'ECOCASH',
            'merchantFee' => 2.50,
        ]),
    ]);

    app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-3', 100.00, 'USD')->withItem('Widget')
    );

    $this->postJson(route('smilepay.callback'), [
        'orderReference' => 'ORDER-3',
        'status' => 'PAID',
    ])->assertOk();

    $transaction = SmilePayTransaction::query()->where('order_reference', 'ORDER-3')->first();

    expect($transaction->isPaid())->toBeTrue()
        ->and($transaction->isVerified())->toBeTrue()
        ->and($transaction->merchantFee()->toDecimal())->toBe('2.50')
        ->and($transaction->netAmount()->toDecimal())->toBe('97.50');

    Event::assertDispatched(PaymentSucceeded::class);
});

it('fires the settled event exactly once across duplicate callbacks', function (): void {
    Event::fake([PaymentSucceeded::class]);

    $paid = [
        'orderReference' => 'ORDER-4',
        'status' => 'PAID',
        'amount' => 10.00,
        'currency' => 'USD',
    ];

    // ZB retries until it gets a 200, so duplicates are routine — not an edge
    // case. Firing twice here would ship the order twice.
    $this->mockGateway([
        $this->jsonResponse(['responseCode' => '00', 'transactionReference' => 'TXN-4']),
        $this->jsonResponse($paid),
        $this->jsonResponse($paid),
        $this->jsonResponse($paid),
    ]);

    app(SmilePay::class)->checkout(
        PaymentRequest::make('ORDER-4', 10.00, 'USD')->withItem('Widget')
    );

    foreach (range(1, 3) as $ignored) {
        $this->postJson(route('smilepay.callback'), $paid)->assertOk();
    }

    Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
});

it('rejects a callback with no order reference', function (): void {
    Event::fake([SuspiciousCallbackDetected::class]);
    $this->mockGateway([]);

    $this->postJson(route('smilepay.callback'), ['status' => 'PAID'])->assertOk();

    Event::assertDispatched(SuspiciousCallbackDetected::class);
});

it('always returns 200 so the gateway stops retrying', function (): void {
    $this->mockGateway([]);

    // Including for callbacks it rejected: retrying a forged request is noise,
    // and a non-200 tells whoever sent it that the endpoint noticed.
    $this->postJson(route('smilepay.callback'), ['garbage' => true])->assertOk();
});

it('never walks a settled transaction backwards', function (): void {
    $this->mockGateway([
        $this->jsonResponse(['responseCode' => '00', 'transactionReference' => 'TXN-5']),
        $this->jsonResponse(['orderReference' => 'ORDER-5', 'status' => 'PAID', 'amount' => 10.00]),
        $this->jsonResponse(['orderReference' => 'ORDER-5', 'status' => 'PENDING', 'amount' => 10.00]),
    ]);

    $gateway = app(SmilePay::class);

    $gateway->checkout(PaymentRequest::make('ORDER-5', 10.00, 'USD')->withItem('Widget'));
    $gateway->verify('ORDER-5');

    // A late, out-of-order update must not un-pay a settled transaction.
    $gateway->verify('ORDER-5');

    $transaction = SmilePayTransaction::query()->where('order_reference', 'ORDER-5')->first();

    expect($transaction->isPaid())->toBeTrue();
});

it('blocks callbacks from outside the configured IP allowlist', function (): void {
    config()->set('smilepay.webhook.allowed_ips', ['196.27.1.0/24']);

    $this->postJson(route('smilepay.callback'), ['orderReference' => 'ORDER-6', 'status' => 'PAID'])
        ->assertNotFound();
});

it('allows callbacks from inside the configured CIDR range', function (): void {
    config()->set('smilepay.webhook.allowed_ips', ['127.0.0.0/8']);

    $this->mockGateway([
        $this->jsonResponse(['orderReference' => 'ORDER-7', 'status' => 'PENDING']),
    ]);

    $this->postJson(route('smilepay.callback'), ['orderReference' => 'ORDER-7'])
        ->assertOk();
});

it('refuses to persist an unverified snapshot', function (): void {
    $gateway = app(SmilePay::class);

    $reflection = new ReflectionMethod($gateway, 'sync');

    $snapshot = new AaronKatema\SmilePay\DTO\TransactionSnapshot(
        orderReference: 'ORDER-8',
        status: TransactionStatus::PAID,
        verified: false,
    );

    expect(fn () => $reflection->invoke($gateway, $snapshot))
        ->toThrow(LogicException::class, 'unverified');
});
