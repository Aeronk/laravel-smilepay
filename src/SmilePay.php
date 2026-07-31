<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay;

use AaronKatema\SmilePay\Contracts\TransactionStore;
use AaronKatema\SmilePay\DTO\CardDetails;
use AaronKatema\SmilePay\DTO\Money;
use AaronKatema\SmilePay\DTO\PaymentRequest;
use AaronKatema\SmilePay\DTO\PaymentResult;
use AaronKatema\SmilePay\DTO\TransactionSnapshot;
use AaronKatema\SmilePay\Enums\PaymentMethod;
use AaronKatema\SmilePay\Enums\TransactionStatus;
use AaronKatema\SmilePay\Events\PaymentCancelled;
use AaronKatema\SmilePay\Events\PaymentFailed;
use AaronKatema\SmilePay\Events\PaymentInitiated;
use AaronKatema\SmilePay\Events\PaymentStatusChanged;
use AaronKatema\SmilePay\Events\PaymentSucceeded;
use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;
use AaronKatema\SmilePay\Exceptions\SmilePayException;
use AaronKatema\SmilePay\Exceptions\TransactionNotFoundException;
use AaronKatema\SmilePay\Http\Client;
use AaronKatema\SmilePay\Models\SmilePayTransaction;
use AaronKatema\SmilePay\Support\Config;
use AaronKatema\SmilePay\Support\Endpoints;
use AaronKatema\SmilePay\Support\Msisdn;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * The Smile&Pay gateway.
 *
 * Everything a merchant needs, in one object:
 *
 *     $result = SmilePay::checkout(
 *         PaymentRequest::make('ORDER-1', 25.00, 'USD')->withItem('Premium plan')
 *     );
 *     return redirect($result->paymentUrl);
 *
 * ## The rule this class is built around
 *
 * Smile&Pay's callbacks carry no signature — no HMAC, no shared secret, nothing
 * that proves a POST to your `resultUrl` came from ZB. Anyone who learns that
 * URL can claim any payment succeeded.
 *
 * So this package never lets a callback body move money. A callback is treated
 * strictly as a *hint that something changed*; on receipt it triggers an
 * authenticated `GET /payments/transaction/{ref}/status/check` over your own
 * credentialled channel, and only that response is allowed to mark a payment
 * settled. One extra round trip, in exchange for a checkout that cannot be
 * talked into shipping for free.
 */
class SmilePay
{
    public function __construct(
        protected readonly Config $config,
        protected readonly Client $client,
        protected readonly TransactionStore $store,
        protected readonly ?Dispatcher $events = null,
        protected readonly ?LoggerInterface $logger = null,
    ) {}

    // ---------------------------------------------------------------------
    // Standard (hosted) checkout
    // ---------------------------------------------------------------------

    /**
     * Create a hosted checkout and get back a URL to redirect the customer to.
     *
     * The recommended integration. Card data never touches your servers, which
     * keeps you on PCI SAQ A, and the customer picks their own rail on ZB's
     * page — leave `method` unset unless you have a reason to force one.
     */
    public function checkout(PaymentRequest $request): PaymentResult
    {
        $request = $this->applyDefaults($request);
        $request->validate();

        $this->warn($request);
        $this->store->starting($request);

        return $this->dispatchInitiation(
            $request,
            Endpoints::INITIATE,
            $request->toGatewayPayload()
        );
    }

    // ---------------------------------------------------------------------
    // Express checkout
    // ---------------------------------------------------------------------

    /**
     * Charge a customer directly from your own UI, without the hosted page.
     *
     * What happens next depends on the rail, so branch on
     * `$result->nextAction()` rather than on the method:
     *
     *   ecocash, onemoney  → USSD prompt sent; poll or await the callback
     *   innbucks           → show `innbucksPaymentCode` / deep link, then poll
     *   walletplus, omari  → collect the SMS OTP, then call `confirmOtp()`
     *   card               → render the 3DS challenge, then await the callback
     *
     * @param  CardDetails|null  $card  Required for CARD, rejected for every
     *                                  other rail. Read the CardDetails docblock
     *                                  before using it — it puts you in PCI scope.
     */
    public function express(PaymentRequest $request, ?CardDetails $card = null): PaymentResult
    {
        $request = $this->applyDefaults($request);
        $request->validate(express: true);

        $method = $request->method;

        if (! $method instanceof PaymentMethod) {
            throw InvalidPaymentRequestException::expressCheckoutNotSupported('unspecified');
        }

        $payload = $this->expressPayload($request, $method, $card);

        $this->warn($request);
        $this->store->starting($request);

        return $this->dispatchInitiation($request, Endpoints::express($method), $payload, $method);
    }

    /** Charge an EcoCash wallet. Sends a USSD prompt to the customer's handset. */
    public function ecocash(PaymentRequest $request): PaymentResult
    {
        return $this->express($request->withMethod(PaymentMethod::ECOCASH));
    }

    /** Charge a OneMoney wallet. Sends a USSD prompt over NetOne. */
    public function oneMoney(PaymentRequest $request): PaymentResult
    {
        return $this->express($request->withMethod(PaymentMethod::ONEMONEY));
    }

    /** Generate an InnBucks payment code for the customer to enter or deep-link. */
    public function innbucks(PaymentRequest $request): PaymentResult
    {
        return $this->express($request->withMethod(PaymentMethod::INNBUCKS));
    }

    /** Start a SmileCash (ZB wallet) payment. Two-step: an OTP follows by SMS. */
    public function smileCash(PaymentRequest $request): PaymentResult
    {
        return $this->express($request->withMethod(PaymentMethod::WALLETPLUS));
    }

    /** Start an O'mari payment. Two-step: an OTP follows by SMS. */
    public function omari(PaymentRequest $request): PaymentResult
    {
        return $this->express($request->withMethod(PaymentMethod::OMARI));
    }

    /**
     * Charge a card directly through MPGS, with a 3DS challenge.
     *
     * Prefer `checkout()`. This method exists because the API offers it, not
     * because it is the right default — see CardDetails for the PCI-DSS
     * consequences of handling a PAN on your own infrastructure.
     */
    public function card(PaymentRequest $request, CardDetails $card): PaymentResult
    {
        if ($card->isExpired()) {
            throw InvalidPaymentRequestException::cardExpired();
        }

        return $this->express($request->withMethod(PaymentMethod::CARD), $card);
    }

    /**
     * Complete a two-step wallet payment with the customer's SMS OTP.
     *
     * Note the trap ZB's own documentation flags: leg 2 keys on the
     * `transactionReference` returned by leg 1, **not** your `orderReference`.
     * Pass the reference from the initiation result.
     *
     * @param  string  $transactionReference  From `$result->transactionReference`.
     * @param  Msisdn|string|null  $mobile  Required for O'mari. Looked up from the
     *                                      transaction store when omitted.
     */
    public function confirmOtp(
        string $transactionReference,
        string $otp,
        PaymentMethod|string $method,
        Msisdn|string|null $mobile = null,
        ?string $orderReference = null,
    ): PaymentResult {
        $method = PaymentMethod::fromLoose($method);

        if (! $method->requiresOtp()) {
            throw InvalidPaymentRequestException::otpNotSupported($method->value);
        }

        $otp = trim($otp);

        if ($otp === '') {
            throw InvalidPaymentRequestException::missingOtp();
        }

        $payload = [
            'transactionReference' => $transactionReference,
            'otp' => $otp,
        ];

        if (Endpoints::confirmNeedsMobile($method)) {
            $resolved = $this->resolveConfirmMobile($mobile, $transactionReference, $orderReference);

            $field = Endpoints::mobileField($method);

            if ($field !== null) {
                $payload[$field] = $resolved->national();
            }
        }

        // Not retried: a repeated OTP submission can burn the customer's
        // remaining attempts and lock the wallet.
        $body = $this->client->post(Endpoints::confirm($method), $payload, idempotent: false);

        // Leg 2 only knows the gateway's reference, so the merchant's order
        // reference has to be recovered from the local row written during leg 1.
        $reference = $orderReference
            ?? $this->store->findByTransactionReference($transactionReference)?->order_reference;

        $result = PaymentResult::fromResponse($body, $reference ?? $transactionReference, $method);

        // The OTP response reports the wallet's answer, but settlement is
        // confirmed the same way as everything else: by asking the gateway.
        // Unconditional on success — skipping it would leave a paid transaction
        // recorded as pending and PaymentSucceeded never fired.
        if ($result->accepted && $reference !== null) {
            $this->verify($reference);
        } elseif ($result->accepted) {
            $this->logger?->warning(
                'Smile&Pay: OTP confirmed but the order reference could not be resolved, so the '
                .'payment was not verified. Pass $orderReference to confirmOtp(), or enable '
                .'transaction persistence.',
                ['transaction_reference' => $transactionReference]
            );
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // Status, verification and cancellation
    // ---------------------------------------------------------------------

    /**
     * Ask the gateway what actually happened, over your authenticated channel.
     *
     * The authoritative answer. Persists the result and fires the appropriate
     * lifecycle events. This is the only path that can mark a payment settled.
     */
    public function verify(string $orderReference): TransactionSnapshot
    {
        $body = $this->client->get(Endpoints::status($orderReference));

        $snapshot = TransactionSnapshot::fromArray($body, $orderReference, verified: true);

        $this->sync($snapshot);

        return $snapshot;
    }

    /**
     * Read-only status check: same call, no persistence, no events.
     *
     * For dashboards and health checks that should not have side effects.
     */
    public function status(string $orderReference): TransactionSnapshot
    {
        $body = $this->client->get(Endpoints::status($orderReference));

        return TransactionSnapshot::fromArray($body, $orderReference, verified: true);
    }

    /**
     * Poll until the transaction reaches a final state or the budget runs out.
     *
     * Intended for a queued job, not a web request — a customer approving a
     * USSD prompt can take a minute or more, and holding a PHP-FPM worker open
     * that long is how a checkout takes the whole site down under load.
     *
     * Backs off linearly and treats an early 404 as "not propagated yet" rather
     * than as a genuine absence.
     *
     * @param  int  $timeoutSeconds  Total wall-clock budget.
     * @param  int  $intervalSeconds  Delay between attempts, grown on each pass.
     */
    public function poll(
        string $orderReference,
        int $timeoutSeconds = 120,
        int $intervalSeconds = 5,
    ): TransactionSnapshot {
        $deadline = time() + $timeoutSeconds;
        $attempt = 0;
        $last = null;

        do {
            $attempt++;

            try {
                $last = $this->verify($orderReference);

                if ($last->isFinal()) {
                    return $last;
                }
            } catch (TransactionNotFoundException $e) {
                // Propagation lag right after initiation is normal. Past the
                // first 30 seconds it is not, and the caller should hear it.
                if (time() > $deadline - $timeoutSeconds + 30) {
                    throw $e;
                }
            }

            $remaining = $deadline - time();

            if ($remaining <= 0) {
                break;
            }

            sleep((int) min($remaining, $intervalSeconds + $attempt));
        } while (time() < $deadline);

        return $last ?? new TransactionSnapshot(
            orderReference: $orderReference,
            status: TransactionStatus::UNKNOWN,
        );
    }

    /**
     * Cancel a pending transaction.
     *
     * Only meaningful before the customer completes payment. Cancelling a
     * settled transaction is a refund, and Smile&Pay does not currently expose
     * a refund endpoint — those go through your ZB merchant portal.
     */
    public function cancel(string $orderReference): TransactionSnapshot
    {
        $body = $this->client->post(Endpoints::cancel($orderReference), null, idempotent: false);

        $snapshot = TransactionSnapshot::fromArray($body, $orderReference, verified: true);

        // The cancel response is terse. Re-read the authoritative status so the
        // local row reflects what the gateway actually did.
        if ($snapshot->status === TransactionStatus::UNKNOWN) {
            return $this->verify($orderReference);
        }

        $this->sync($snapshot);

        return $snapshot;
    }

    // ---------------------------------------------------------------------
    // Accessors
    // ---------------------------------------------------------------------

    public function config(): Config
    {
        return $this->config;
    }

    public function store(): TransactionStore
    {
        return $this->store;
    }

    /**
     * Look up the local record for an order reference.
     */
    public function transaction(string $orderReference): ?SmilePayTransaction
    {
        return $this->store->find($orderReference);
    }

    /**
     * Reconcile every stale open transaction against the gateway.
     *
     * The safety net for everything that can go wrong asynchronously: a
     * callback that never arrived, a poll job that died, a deploy mid-checkout.
     * Run it on a schedule — every five minutes is a reasonable default.
     *
     * @return array<string, TransactionStatus> Order reference => resolved status.
     */
    public function reconcile(int $olderThanSeconds = 300, int $limit = 100): array
    {
        $resolved = [];

        foreach ($this->store->pending($olderThanSeconds, $limit) as $transaction) {
            try {
                $snapshot = $this->verify($transaction->order_reference);
                $resolved[$transaction->order_reference] = $snapshot->status;
            } catch (SmilePayException $e) {
                $this->logger?->warning('Smile&Pay reconciliation failed', [
                    'order_reference' => $transaction->order_reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $resolved;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Persist a verified snapshot and dispatch the resulting lifecycle events.
     *
     * Refuses to act on an unverified snapshot. That single guard is what stops
     * a forged callback from reaching a listener that ships goods.
     */
    protected function sync(TransactionSnapshot $snapshot): ?SmilePayTransaction
    {
        if (! $snapshot->verified) {
            throw new \LogicException(
                'Refusing to persist an unverified transaction snapshot. Smile&Pay callbacks '
                .'are unsigned, so state may only be written from an authenticated status check.'
            );
        }

        $existing = $this->store->find($snapshot->orderReference);
        $previous = $existing?->status ?? TransactionStatus::PENDING;

        $transaction = $this->store->synced($snapshot);

        // Compare against what was actually persisted. When the store refuses a
        // backwards transition the row keeps its old status, and firing a
        // PAID -> PENDING event for a write that never happened would tell
        // listeners a settled order had come un-settled.
        $current = $transaction?->status ?? $snapshot->status;

        if ($existing === null && $this->config->persistTransactions) {
            // The gateway recognised a reference we have no record of. Because
            // the status endpoint is scoped to our credentials the payment is
            // genuinely ours, so this usually means another system shares the
            // merchant account — or that a row was lost. Either way it is worth
            // surfacing rather than silently emitting a settled event for an
            // order this application never created.
            $this->logger?->warning('Smile&Pay: verified a transaction with no local record', [
                'order_reference' => $snapshot->orderReference,
                'status' => $snapshot->status->value,
            ]);
        }

        if ($previous === $current) {
            return $transaction;
        }

        $this->fire(new PaymentStatusChanged($snapshot, $previous, $current, $transaction));

        // Terminal events fire once, on the transition into the final state —
        // never on a repeat notification for a state we already recorded.
        match (true) {
            $current === TransactionStatus::PAID => $this->fire(
                new PaymentSucceeded($snapshot, $transaction)
            ),
            $current === TransactionStatus::CANCELLED => $this->fire(
                new PaymentCancelled($snapshot, $transaction)
            ),
            $current->isUnsuccessful() => $this->fire(
                new PaymentFailed($snapshot, $transaction, $current->label())
            ),
            default => null,
        };

        return $transaction;
    }

    /**
     * Send an initiation request and record the outcome.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function dispatchInitiation(
        PaymentRequest $request,
        string $endpoint,
        array $payload,
        ?PaymentMethod $method = null,
    ): PaymentResult {
        try {
            // Never retried. The gateway may already have created the
            // transaction and prompted the customer; a blind retry risks
            // charging them twice. Recovery is reconciliation, not repetition.
            $body = $this->client->post($endpoint, $payload, idempotent: false);
        } catch (SmilePayException $e) {
            $this->store->failed($request, $e->getMessage());

            throw $e;
        }

        $result = PaymentResult::fromResponse($body, $request->orderReference, $method ?? $request->method);

        $transaction = $this->store->initiated($request, $result);

        if ($result->accepted) {
            $this->fire(new PaymentInitiated($request, $result, $transaction));
        }

        return $result;
    }

    /**
     * Build the express payload for a rail, including its bespoke mobile field.
     *
     * @return array<string, mixed>
     */
    protected function expressPayload(
        PaymentRequest $request,
        PaymentMethod $method,
        ?CardDetails $card,
    ): array {
        $payload = $request->toGatewayPayload();

        // Express rails identify themselves by endpoint; the documented bodies
        // carry no paymentMethod field except on the card endpoint.
        if ($method !== PaymentMethod::CARD) {
            unset($payload['paymentMethod'], $payload['returnUrl']);
        }

        $field = Endpoints::mobileField($method);

        if ($field !== null && $request->customer->msisdn instanceof Msisdn) {
            $payload[$field] = $request->customer->msisdn->national();
        }

        if ($method === PaymentMethod::CARD) {
            if (! $card instanceof CardDetails) {
                throw InvalidPaymentRequestException::missingCard();
            }

            $payload = [...$payload, ...$card->toGatewayPayload()];
        } elseif ($card instanceof CardDetails) {
            throw InvalidPaymentRequestException::invalidCard(
                sprintf('card details were supplied for the %s rail, which does not take them', $method->label())
            );
        }

        return $payload;
    }

    /**
     * Work out which mobile number to echo on an O'mari OTP confirmation.
     */
    protected function resolveConfirmMobile(
        Msisdn|string|null $mobile,
        string $transactionReference,
        ?string $orderReference,
    ): Msisdn {
        if ($mobile !== null) {
            return Msisdn::parse($mobile);
        }

        // The stored number is masked for privacy, so it cannot be replayed to
        // the gateway. The caller has to supply it.
        $known = $orderReference !== null
            ? $this->store->find($orderReference)
            : $this->store->findByTransactionReference($transactionReference);

        throw InvalidPaymentRequestException::missingMsisdn(sprintf(
            "O'mari OTP confirmation for %s requires the customer's mobile number. "
            .'Pass it to confirmOtp() — it is stored masked and cannot be recovered%s.',
            $transactionReference,
            $known instanceof SmilePayTransaction ? '' : ', and no local record was found'
        ));
    }

    /**
     * Fill blank URLs and item details from config.
     */
    protected function applyDefaults(PaymentRequest $request): PaymentRequest
    {
        return $request->withDefaults(
            returnUrl: $this->config->defaultReturnUrl(),
            resultUrl: $this->config->defaultResultUrl(),
            itemName: $this->config->defaultItemName(),
            itemDescription: $this->config->defaultItemDescription(),
        );
    }

    protected function warn(PaymentRequest $request): void
    {
        foreach ($request->warnings() as $warning) {
            $this->logger?->warning('Smile&Pay: '.$warning, [
                'order_reference' => $request->orderReference,
            ]);
        }
    }

    protected function fire(object $event): void
    {
        $this->events?->dispatch($event);
    }

    /**
     * Convenience passthrough so `SmilePay::money(25, 'USD')` reads naturally.
     */
    public function money(Money|int|float|string $amount, string $currency = 'USD'): Money
    {
        return $amount instanceof Money ? $amount : Money::fromDecimal($amount, $currency);
    }
}
