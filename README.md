# Laravel Smile&Pay

A production-grade Laravel SDK for **Smile&Pay**, ZB Bank's Zimbabwean payment gateway.

Supports EcoCash, OneMoney, O'mari, InnBucks, SmileCash (WalletPlus) and Visa/Mastercard — through both hosted Standard Checkout and Express Checkout, with a verified webhook pipeline, a full transaction audit trail and a reconciliation command.

```php
$result = SmilePay::checkout(
    PaymentRequest::make('ORDER-12345', 100.00, 'USD')
        ->withItem('Premium Subscription', '1 Month Premium Access')
);

return redirect($result->paymentUrl);
```

---

## Read this first: the security model

**Smile&Pay callbacks are unsigned.** There is no HMAC, no shared secret, no mutual TLS — just a JSON body POSTed to your `resultUrl`. That URL is not a secret either: it travels in every payment you initiate and is echoed back inside the callback payload.

The obvious integration reads `status: "PAID"` from the callback and marks the order paid. That integration ships goods to anyone who can write a POST request:

```bash
curl -X POST https://yoursite.com/api/webhook \
  -H 'Content-Type: application/json' \
  -d '{"orderReference":"ORDER-12345","status":"PAID","amount":100.00}'
```

**This package never lets a callback body move money.**

A callback is treated as a *hint that something changed*. On receipt, the package calls `GET /payments/transaction/{orderReference}/status/check` over your own authenticated channel, and only that answer is allowed to change state. One extra round trip, in exchange for a checkout that cannot be talked into shipping for free.

When the callback and the gateway disagree — a body claiming `PAID` for a transaction ZB reports as `PENDING` — the package records it, refuses to act, and fires `SuspiciousCallbackDetected`. **Alert on that event.** A trickle usually means someone found your webhook URL and is testing whether you check. A burst means they stopped testing.

---

## Installation

```bash
composer require aaronkatema/laravel-smilepay
php artisan vendor:publish --tag=smilepay-config
php artisan vendor:publish --tag=smilepay-migrations
php artisan migrate
```

**Requirements:** PHP 8.2+, Laravel 11, 12 or 13.

### Credentials

Register at the [Smile&Pay Sandbox Portal](https://zbnet.zb.co.zw/wallet_sandbox_merchant/), then generate an API key and secret under **Settings → API Keys**.

```dotenv
SMILEPAY_ENV=sandbox

SMILEPAY_SANDBOX_KEY=your_sandbox_key
SMILEPAY_SANDBOX_SECRET=your_sandbox_secret

SMILEPAY_PRODUCTION_KEY=your_production_key
SMILEPAY_PRODUCTION_SECRET=your_production_secret

SMILEPAY_RETURN_URL="${APP_URL}/payment/return"
SMILEPAY_RESULT_URL="${APP_URL}/smilepay/callback"
```

> The API secret is a password. It belongs in `.env` on your server and nowhere else — never in a repository, never in a frontend bundle, never in a mobile app. ZB's own card documentation demonstrates calling the API from browser JavaScript with both credentials inline. **Do not copy that pattern.** Anyone who opens devtools gets your merchant credentials.

### Verify your setup

```bash
php artisan smilepay:status
```

Prints the resolved environment, base URL, masked credentials, and flags any unsafe setting.

---

## Standard Checkout (recommended)

Redirect the customer to ZB's hosted page. Card data never touches your servers, which keeps you on **PCI SAQ A** rather than SAQ D.

```php
use AaronKatema\SmilePay\DTO\Customer;
use AaronKatema\SmilePay\DTO\PaymentRequest;
use AaronKatema\SmilePay\Facades\SmilePay;

public function pay(Order $order)
{
    $result = SmilePay::checkout(
        PaymentRequest::make($order->reference, $order->total, 'USD')
            ->withItem($order->title, $order->description)
            ->withCustomer(Customer::make(
                msisdn: $order->customer->phone,
                email: $order->customer->email,
                firstName: $order->customer->name,
            ))
            ->withMetadata(['order_id' => $order->id, 'tenant_id' => $order->tenant_id])
    );

    if ($result->failed()) {
        return back()->withErrors($result->message);
    }

    return redirect()->away($result->paymentUrl);
}
```

Leave the payment method unset and the customer picks their own rail on ZB's page.

`metadata` never leaves your application — the API has no custom-field support. It is stored on the local transaction row and rejoined by `orderReference` when the callback arrives.

---

## Express Checkout

Charge directly from your own UI. Each rail behaves differently, so branch on `nextAction()` rather than on the method:

```php
$result = SmilePay::express(
    PaymentRequest::make('ORDER-1', 25.00, 'USD')
        ->withItem('Premium plan')
        ->withMethod(PaymentMethod::ECOCASH)
        ->withMsisdn('0771234567')
);

return match ($result->nextAction()) {
    'poll'          => view('checkout.waiting', ['reference' => $result->orderReference]),
    'innbucks_code' => view('checkout.innbucks', [
                           'code' => $result->innbucksPaymentCode,
                           'link' => $result->innbucksDeepLink(),
                       ]),
    'otp'           => view('checkout.otp', ['txn' => $result->transactionReference]),
    'three_ds'      => view('checkout.3ds', ['challenge' => $result->challenge]),
    'redirect'      => redirect()->away($result->paymentUrl),
    'failed'        => back()->withErrors($result->message),
};
```

### Per-rail shortcuts

```php
SmilePay::ecocash($request->withMsisdn('0771234567'));    // USSD push
SmilePay::oneMoney($request->withMsisdn('0713456789'));   // USSD push
SmilePay::innbucks($request);                             // payment code + deep link
SmilePay::smileCash($request->withMsisdn('0711111111'));  // two-step, SMS OTP
SmilePay::omari($request->withMsisdn('0731234567'));      // two-step, SMS OTP
```

### Two-step rails (SmileCash, O'mari)

Leg 1 triggers an SMS OTP. Leg 2 confirms it.

```php
// Leg 1
$result = SmilePay::smileCash(
    PaymentRequest::make('ORDER-1', 25.00, 'USD')
        ->withItem('Premium plan')
        ->withMsisdn('0711111111')
);

session(['smilepay_txn' => $result->transactionReference]);

// Leg 2 — after the customer types the code
$confirmed = SmilePay::confirmOtp(
    transactionReference: session('smilepay_txn'),
    otp: $request->input('otp'),
    method: PaymentMethod::WALLETPLUS,
    orderReference: 'ORDER-1',
);
```

> **The trap ZB's own docs flag:** leg 2 keys on the `transactionReference` returned by leg 1, **not** your `orderReference`. O'mari additionally requires the mobile number to be echoed back — pass it as the `mobile` argument. The package stores numbers masked and will not guess.

### Card payments (MPGS)

```php
$result = SmilePay::card(
    PaymentRequest::make('ORDER-1', 10.00, 'USD')->withItem('Widget'),
    CardDetails::fromExpiryString('5123450000000008', '01/39', '100')
);

// Prefer the structured challenge over ZB's redirectHtml
if ($result->challenge?->hasStructuredChallenge()) {
    return view('checkout.3ds', [
        'html' => $result->challenge->toSafeHtml(target: '3ds-frame'),
    ]);
}
```

> **This puts you in PCI-DSS scope.** Passing a raw PAN through your own server moves you from SAQ A to **SAQ D** — quarterly ASV scans, penetration testing, network segmentation, an annual audit — and it applies to every machine the data touches, including log aggregators, queue workers and backups. Unless you have a concrete commercial reason and a compliance programme to match, use `checkout()` instead.
>
> `CardDetails` defends what it can: it never appears in `var_dump`, `dd()`, `json_encode`, logs or exception traces, and refuses to be unserialised so a card cannot end up in a queue payload or session.
>
> On the 3DS challenge, prefer `toSafeHtml()`. ZB returns a `redirectHtml` blob whose `<script>` you are told to extract and execute yourself — because browsers correctly refuse to run scripts inserted via `innerHTML`. Doing that means any change on ZB's side executes in your origin. `toSafeHtml()` posts the same `acsUrl` and `cReq` from a form you control.

---

## Handling payment results

### Webhooks

The package registers `POST /smilepay/callback` automatically. Point `SMILEPAY_RESULT_URL` at it and listen for events:

```php
use AaronKatema\SmilePay\Events\PaymentSucceeded;

class FulfilOrder
{
    public function handle(PaymentSucceeded $event): void
    {
        $order = Order::where('reference', $event->orderReference())->firstOrFail();

        $order->markPaid(
            amount: $event->snapshot->amount,
            fee: $event->snapshot->merchantFee,
        );
    }
}
```

`PaymentSucceeded` is the **only** event that should release value. It carries two guarantees:

1. The snapshot came from an authenticated status check, never from a callback body.
2. It fires exactly once per order reference. ZB retries callbacks until it gets a 200, so duplicates are routine — the package deduplicates them.

Other events: `PaymentInitiated`, `PaymentFailed`, `PaymentCancelled`, `PaymentStatusChanged`, `WebhookReceived`, `SuspiciousCallbackDetected`.

### Polling

For rails without a reliable callback, or as a belt-and-braces check. Run it in a **queued job**, never in a web request — a customer approving a USSD prompt can take a minute, and holding a PHP-FPM worker open that long is how a checkout takes the whole site down under load.

```php
class PollSmilePayTransaction implements ShouldQueue
{
    public function __construct(private string $orderReference) {}

    public function handle(SmilePay $smilepay): void
    {
        $snapshot = $smilepay->poll($this->orderReference, timeoutSeconds: 120);

        if ($snapshot->isPending()) {
            $this->release(60);
        }
    }
}
```

### Reconciliation

Payments fail asynchronously. A customer walks away from a USSD prompt, a callback is lost, a deploy kills a poll job mid-flight — and the transaction sits open while nobody knows whether you were paid.

```php
// routes/console.php
Schedule::command('smilepay:reconcile')->everyFiveMinutes();
```

**A Smile&Pay integration without this scheduled is not finished**, however well the happy path works.

```bash
php artisan smilepay:reconcile --dry-run    # see what would be checked
php artisan smilepay:status ORDER-12345     # local record beside the gateway's
```

---

## Hardening the webhook endpoint

Because callbacks are unsigned, add what defence you can:

```dotenv
# Ask your ZB integration contact for their egress range
SMILEPAY_ALLOWED_IPS="196.27.0.0/16,41.79.0.0/18"

# Secret path segment: /smilepay/callback/9f2c3d...
SMILEPAY_WEBHOOK_SECRET_PATH=9f2c3d4e5f6a7b8c
```

Neither is authentication — an IP can be spoofed, a URL is not a credential — but together they take the endpoint from "anyone with the URL" to "anyone who can source traffic from ZB's range and knows a secret path". The real guarantee remains the status check.

> Behind a load balancer, configure Laravel's `TrustProxies` first, or `$request->ip()` returns the balancer and the allowlist either blocks everything or trusts a forged `X-Forwarded-For`.

---

## Testing

```php
use AaronKatema\SmilePay\Facades\SmilePay;

it('fulfils an order once paid', function () {
    $fake = SmilePay::fake()->willSucceed('ORDER-1');

    $this->post('/checkout', ['reference' => 'ORDER-1', 'amount' => 25.00]);

    $fake->assertInitiated('ORDER-1')
         ->assertMethodUsed('ORDER-1', PaymentMethod::ECOCASH)
         ->assertPaid('ORDER-1');
});
```

The fake makes no network calls but runs the real persistence and event pipeline, so a passing test exercises the same listeners production will.

Scripting helpers: `willSucceed()`, `willFail()`, `willStayPending()`, `willRejectInitiation()`, `willReturn()`.
Assertions: `assertInitiated()`, `assertNotInitiated()`, `assertNothingInitiated()`, `assertInitiatedCount()`, `assertPaid()`, `assertMethodUsed()`, `assertCancelled()`, `assertOtpConfirmed()`.

### Sandbox test data

| Rail | Test value |
|---|---|
| EcoCash | `263788687707` (approval is triggered manually — contact the Smile&Pay team) |
| OneMoney success | `0713456789` |
| OneMoney failure | `0713456780` |
| SmileCash | `0711111111` |
| O'mari | `0731234567` |
| SMS OTP | `000000` |
| Card — 3DS success | `5123450000000008` |
| Card — system error | `5123450000000002` * |
| Card — declined | `5123450000000010` * |
| Card CVV / expiry | `100` / `01/39` |

\* These two PANs **do not satisfy the Luhn checksum**, so `CardDetails::make()` rejects them by default. To exercise the failure paths, pass `strictLuhn: false`:

```php
CardDetails::make('5123450000000010', '01', '39', '100', strictLuhn: false);
```

Keep the check on in production — it catches customer typos before they cost a gateway call and a decline on the card's record. Whether the invalid checksums are deliberate on ZB's side or a documentation typo is worth confirming with your integration contact.

---

## Design decisions worth knowing

**Money is stored in integer minor units.** `Money::fromDecimal('0.10')->plus(...'0.20')` gives exactly `0.30`. A float-based implementation cannot promise that, and the discrepancy surfaces in a merchant's ledger rather than in your tests.

**Currency is transmitted as ISO numeric.** Smile&Pay wants `"840"` and `"924"`, not `"USD"` and `"ZWG"`. The package keeps the readable code in your code and database and converts only at the wire boundary.

**Initiation is never retried automatically.** A timeout does not prove the transaction was not created — the customer may already have been prompted. Recovery is reconciliation, not repetition. Idempotent calls (status checks) *are* retried, with exponential backoff and full jitter so a fleet coming out of a ZB outage does not stampede it back down.

**The transaction row is written before the gateway is called.** If the call then fails, the payment is still visible and reconcilable. Write-after leaves an indeterminate payment nobody knows to look for.

**HTTP 200 is not success.** The gateway returns 200 with `responseCode: "51"` for a decline. Every response passes through the response-code check — this is the single most common way to build a payment integration that ships goods for free.

**An unknown status degrades to `UNKNOWN`, never `PAID`.** A false PAID gives goods away; a false UNKNOWN just triggers another status check. Add new ZB statuses via `smilepay.status_map` without touching code.

**Secrets and card data never reach a log.** Headers and bodies pass through a redactor before any logging, and contact details are masked rather than stored in the clear. A payments table links names to numbers to amounts; keeping it masked stops one leak becoming a ready-made target list.

**A final state is never walked backwards.** A late `PENDING` callback cannot un-pay a settled transaction. Enforced inside a row lock, because callbacks do not arrive in order.

---

## Configuration reference

| Key | Env | Default | Notes |
|---|---|---|---|
| `environment` | `SMILEPAY_ENV` | `sandbox` | Auto-switches on `APP_ENV=production` |
| `default_currency` | `SMILEPAY_CURRENCY` | `USD` | `USD` or `ZWG` |
| `defaults.return_url` | `SMILEPAY_RETURN_URL` | — | Required for hosted checkout |
| `defaults.result_url` | `SMILEPAY_RESULT_URL` | — | Falls back to the package route |
| `webhook.verify_with_status_check` | `SMILEPAY_VERIFY_CALLBACKS` | `true` | **Leave on.** Ignored in production |
| `webhook.allowed_ips` | `SMILEPAY_ALLOWED_IPS` | — | CIDR or plain IPs |
| `webhook.secret_path` | `SMILEPAY_WEBHOOK_SECRET_PATH` | — | Extra path segment |
| `database.enabled` | `SMILEPAY_PERSIST` | `true` | Disabling removes dedup + reconciliation |
| `http.timeout` | `SMILEPAY_TIMEOUT` | `30` | Wallet initiations can be slow |
| `http.verify_ssl` | `SMILEPAY_VERIFY_SSL` | `true` | Refused in production if `false` |
| `retry.attempts` | `SMILEPAY_RETRY_ATTEMPTS` | `3` | Idempotent calls only |
| `reconciliation.stale_after_seconds` | `SMILEPAY_STALE_AFTER` | `300` | |

### TLS note

Some `zb.co.zw` hosts have been observed serving an **incomplete certificate chain**, which strict clients reject. The fix is to install the missing intermediate on your server, or point Guzzle at an updated CA bundle — **not** to disable verification. Without it, anyone on the path can read your API secret and rewrite payment instructions. The package refuses to boot with verification off in production.

---

## API surface

```php
SmilePay::checkout(PaymentRequest $request): PaymentResult
SmilePay::express(PaymentRequest $request, ?CardDetails $card = null): PaymentResult
SmilePay::ecocash|oneMoney|innbucks|smileCash|omari(PaymentRequest $request): PaymentResult
SmilePay::card(PaymentRequest $request, CardDetails $card): PaymentResult
SmilePay::confirmOtp(string $txnRef, string $otp, PaymentMethod|string $method, $mobile = null, ?string $orderRef = null): PaymentResult

SmilePay::verify(string $orderReference): TransactionSnapshot   // authoritative, persists + fires events
SmilePay::status(string $orderReference): TransactionSnapshot   // read-only, no side effects
SmilePay::poll(string $orderReference, int $timeout = 120): TransactionSnapshot
SmilePay::cancel(string $orderReference): TransactionSnapshot

SmilePay::transaction(string $orderReference): ?SmilePayTransaction
SmilePay::reconcile(int $olderThan = 300, int $limit = 100): array
```

---

## Known gaps in the upstream API

Not limitations of this package — things Smile&Pay does not currently expose:

- **No webhook signature.** Mitigated by mandatory status-check verification.
- **No refund endpoint.** Refunds go through the ZB merchant portal. `cancel()` only works on transactions that have not yet completed.
- **No idempotency header.** The package derives its own key for local deduplication, but cannot ask the gateway to deduplicate.
- **No custom metadata fields.** Merchant context is stored locally and rejoined by `orderReference`.
- **No documented rate limits.** The client handles 429 with `Retry-After` if one ever appears.

If ZB adds any of these, the seams are already in place — `Endpoints` for paths, `status_map` for vocabulary, `TransactionStore` for persistence.

---

## Licence

MIT © Aaron Gibson Katema
