<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Testing;

use AaronKatema\SmilePay\Contracts\TransactionStore;
use AaronKatema\SmilePay\DTO\CardDetails;
use AaronKatema\SmilePay\DTO\Money;
use AaronKatema\SmilePay\DTO\PaymentRequest;
use AaronKatema\SmilePay\DTO\PaymentResult;
use AaronKatema\SmilePay\DTO\TransactionSnapshot;
use AaronKatema\SmilePay\Enums\PaymentMethod;
use AaronKatema\SmilePay\Enums\TransactionStatus;
use AaronKatema\SmilePay\Http\Client;
use AaronKatema\SmilePay\SmilePay;
use AaronKatema\SmilePay\Support\Config;
use AaronKatema\SmilePay\Support\Msisdn;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Assert;

/**
 * An in-memory Smile&Pay that makes no network calls.
 *
 * Lets a checkout be tested end to end — every rail, the OTP legs, polling and
 * the callback pipeline — without a sandbox account or a live connection:
 *
 *     $fake = SmilePay::fake();
 *     $fake->willSucceed('ORDER-1');
 *
 *     $this->post('/checkout', ['reference' => 'ORDER-1']);
 *
 *     $fake->assertInitiated('ORDER-1');
 *     $fake->assertPaid('ORDER-1');
 *
 * The fake still runs the real persistence and event pipeline, so a test that
 * passes here exercises the same listeners production will. Only the transport
 * is replaced.
 */
final class SmilePayFake extends SmilePay
{
    /** @var list<array{method: string, request: PaymentRequest}> */
    private array $initiations = [];

    /** @var list<array{transactionReference: string, otp: string, method: PaymentMethod}> */
    private array $otpConfirmations = [];

    /** @var array<string, TransactionStatus> */
    private array $statuses = [];

    /** @var array<string, PaymentResult> */
    private array $scriptedResults = [];

    /** @var list<string> */
    private array $cancelled = [];

    public function __construct(
        Config $config,
        TransactionStore $store,
        ?Dispatcher $events = null,
    ) {
        parent::__construct($config, new Client($config), $store, $events);
    }

    // -----------------------------------------------------------------
    // Scripting
    // -----------------------------------------------------------------

    /**
     * The next status check for this reference reports PAID.
     */
    public function willSucceed(string $orderReference): self
    {
        $this->statuses[$orderReference] = TransactionStatus::PAID;

        return $this;
    }

    /**
     * Initiation is accepted, but the payment ultimately fails.
     */
    public function willFail(string $orderReference, string $responseCode = '51'): self
    {
        $this->statuses[$orderReference] = TransactionStatus::FAILED;
        $this->scriptedResults[$orderReference] = new PaymentResult(
            accepted: false,
            orderReference: $orderReference,
            responseCode: $responseCode,
            message: 'Simulated failure',
            status: TransactionStatus::FAILED,
        );

        return $this;
    }

    /**
     * Initiation is accepted and the payment stays pending — the abandoned
     * USSD prompt that reconciliation exists to clean up.
     */
    public function willStayPending(string $orderReference): self
    {
        $this->statuses[$orderReference] = TransactionStatus::PROCESSING;

        return $this;
    }

    /**
     * Initiation is rejected outright by the gateway.
     */
    public function willRejectInitiation(string $orderReference, string $responseCode = '30'): self
    {
        $this->scriptedResults[$orderReference] = new PaymentResult(
            accepted: false,
            orderReference: $orderReference,
            responseCode: $responseCode,
            message: 'Simulated rejection',
            status: TransactionStatus::FAILED,
        );

        return $this;
    }

    /**
     * Supply an exact result for full control over an edge case.
     */
    public function willReturn(string $orderReference, PaymentResult $result): self
    {
        $this->scriptedResults[$orderReference] = $result;

        return $this;
    }

    // -----------------------------------------------------------------
    // Overridden gateway behaviour
    // -----------------------------------------------------------------

    public function checkout(PaymentRequest $request): PaymentResult
    {
        $request = $this->prepare($request);

        // Validated exactly as the real gateway does. A fake that accepts a
        // request production would reject is worse than no fake at all — it
        // turns a green suite into false confidence.
        $request->validate();

        $this->initiations[] = ['method' => 'checkout', 'request' => $request];
        $this->store->starting($request);

        $result = $this->scriptedResults[$request->orderReference] ?? new PaymentResult(
            accepted: true,
            orderReference: $request->orderReference,
            transactionReference: $this->reference($request->orderReference),
            method: $request->method,
            paymentUrl: 'https://zbnet.zb.co.zw/wallet_sandbox_checkout?reference='
                .$this->reference($request->orderReference),
            responseCode: '00',
            message: 'Transaction initiated successfully',
            status: TransactionStatus::PENDING,
        );

        $this->store->initiated($request, $result);

        return $result;
    }

    public function express(PaymentRequest $request, ?CardDetails $card = null): PaymentResult
    {
        $request = $this->prepare($request);
        $request->validate(express: true);

        $method = $request->method ?? PaymentMethod::ECOCASH;

        $this->initiations[] = ['method' => 'express:'.$method->value, 'request' => $request];
        $this->store->starting($request);

        $result = $this->scriptedResults[$request->orderReference] ?? new PaymentResult(
            accepted: true,
            orderReference: $request->orderReference,
            transactionReference: $this->reference($request->orderReference),
            method: $method,
            responseCode: '00',
            message: 'Transaction initiated successfully',
            status: $method->requiresOtp() ? TransactionStatus::AWAITING_OTP : TransactionStatus::PROCESSING,
            innbucksPaymentCode: $method === PaymentMethod::INNBUCKS ? '701564' : null,
        );

        $this->store->initiated($request, $result);

        return $result;
    }

    public function confirmOtp(
        string $transactionReference,
        string $otp,
        PaymentMethod|string $method,
        Msisdn|string|null $mobile = null,
        ?string $orderReference = null,
    ): PaymentResult {
        $method = PaymentMethod::fromLoose($method);

        $this->otpConfirmations[] = [
            'transactionReference' => $transactionReference,
            'otp' => $otp,
            'method' => $method,
        ];

        // The sandbox accepts 000000 and nothing else, so the fake mirrors that
        // — a test that "passes" with any OTP is testing nothing.
        $accepted = $otp === '000000';

        $reference = $orderReference
            ?? $this->store->findByTransactionReference($transactionReference)?->order_reference;

        if ($accepted && $reference !== null) {
            $this->statuses[$reference] ??= TransactionStatus::PAID;
            $this->verify($reference);
        }

        $reference ??= $transactionReference;

        return new PaymentResult(
            accepted: $accepted,
            orderReference: $reference,
            transactionReference: $transactionReference,
            method: $method,
            responseCode: $accepted ? '00' : '55',
            message: $accepted ? 'Transaction successful' : 'Incorrect PIN or OTP',
            status: $accepted ? TransactionStatus::PAID : TransactionStatus::FAILED,
        );
    }

    public function verify(string $orderReference): TransactionSnapshot
    {
        $snapshot = $this->snapshot($orderReference);

        $this->sync($snapshot);

        return $snapshot;
    }

    public function status(string $orderReference): TransactionSnapshot
    {
        return $this->snapshot($orderReference);
    }

    public function poll(
        string $orderReference,
        int $timeoutSeconds = 120,
        int $intervalSeconds = 5,
    ): TransactionSnapshot {
        // No sleeping in tests.
        return $this->verify($orderReference);
    }

    public function cancel(string $orderReference): TransactionSnapshot
    {
        $this->cancelled[] = $orderReference;
        $this->statuses[$orderReference] = TransactionStatus::CANCELLED;

        return $this->verify($orderReference);
    }

    // -----------------------------------------------------------------
    // Assertions
    // -----------------------------------------------------------------

    public function assertInitiated(string $orderReference, ?callable $callback = null): self
    {
        $matches = array_filter(
            $this->initiations,
            static fn (array $entry) => $entry['request']->orderReference === $orderReference
        );

        Assert::assertNotEmpty(
            $matches,
            sprintf('Expected a payment to be initiated for [%s], but none was.', $orderReference)
        );

        if ($callback !== null) {
            $satisfied = false;

            foreach ($matches as $entry) {
                if ($callback($entry['request']) === true) {
                    $satisfied = true;

                    break;
                }
            }

            Assert::assertTrue(
                $satisfied,
                sprintf('An initiation for [%s] exists but none satisfied the callback.', $orderReference)
            );
        }

        return $this;
    }

    public function assertNotInitiated(string $orderReference): self
    {
        Assert::assertEmpty(
            array_filter(
                $this->initiations,
                static fn (array $entry) => $entry['request']->orderReference === $orderReference
            ),
            sprintf('Expected no payment for [%s], but one was initiated.', $orderReference)
        );

        return $this;
    }

    public function assertNothingInitiated(): self
    {
        Assert::assertEmpty(
            $this->initiations,
            sprintf('Expected no payments, but %d were initiated.', count($this->initiations))
        );

        return $this;
    }

    public function assertInitiatedCount(int $expected): self
    {
        Assert::assertCount($expected, $this->initiations);

        return $this;
    }

    public function assertPaid(string $orderReference): self
    {
        $transaction = $this->store->find($orderReference);

        Assert::assertNotNull(
            $transaction,
            sprintf('No transaction recorded for [%s].', $orderReference)
        );
        Assert::assertTrue(
            $transaction->isPaid(),
            sprintf(
                'Expected [%s] to be paid, but it is %s.',
                $orderReference,
                $transaction->status->value
            )
        );

        return $this;
    }

    public function assertMethodUsed(string $orderReference, PaymentMethod $method): self
    {
        $used = array_map(
            static fn (array $entry) => $entry['request']->method,
            array_filter(
                $this->initiations,
                static fn (array $entry) => $entry['request']->orderReference === $orderReference
            )
        );

        Assert::assertContains(
            $method,
            $used,
            sprintf('Expected [%s] to be charged via %s.', $orderReference, $method->label())
        );

        return $this;
    }

    public function assertCancelled(string $orderReference): self
    {
        Assert::assertContains(
            $orderReference,
            $this->cancelled,
            sprintf('Expected [%s] to be cancelled.', $orderReference)
        );

        return $this;
    }

    public function assertOtpConfirmed(string $transactionReference): self
    {
        Assert::assertNotEmpty(
            array_filter(
                $this->otpConfirmations,
                static fn (array $entry) => $entry['transactionReference'] === $transactionReference
            ),
            sprintf('Expected an OTP confirmation for [%s].', $transactionReference)
        );

        return $this;
    }

    /**
     * @return list<array{method: string, request: PaymentRequest}>
     */
    public function initiations(): array
    {
        return $this->initiations;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function snapshot(string $orderReference): TransactionSnapshot
    {
        $status = $this->statuses[$orderReference] ?? TransactionStatus::PENDING;
        $transaction = $this->store->find($orderReference);

        return new TransactionSnapshot(
            orderReference: $orderReference,
            status: $status,
            transactionReference: $this->reference($orderReference),
            amount: $transaction?->amount() ?? Money::fromMinor(0, $this->config->defaultCurrency),
            method: $transaction?->method,
            verified: true,
            raw: ['status' => strtoupper($status->value), 'orderReference' => $orderReference],
        );
    }

    private function prepare(PaymentRequest $request): PaymentRequest
    {
        return $request->withDefaults(
            returnUrl: $this->config->defaultReturnUrl() ?? 'https://example.test/return',
            resultUrl: $this->config->defaultResultUrl() ?? 'https://example.test/callback',
            itemName: $this->config->defaultItemName() ?? 'Test item',
            itemDescription: $this->config->defaultItemDescription() ?? 'Test item description',
        );
    }

    private function reference(string $orderReference): string
    {
        return 'TXN-'.substr(hash('sha256', $orderReference), 0, 12);
    }
}
