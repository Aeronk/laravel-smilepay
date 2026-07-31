<?php

declare(strict_types=1);

use AaronKatema\SmilePay\Enums\PaymentMethod;

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | "sandbox" or "production". Sandbox transactions are simulated and move no
    | real money. Keep this tied to your app environment so a staging deploy can
    | never reach the live gateway with test data.
    |
    */

    'environment' => env('SMILEPAY_ENV', env('APP_ENV') === 'production' ? 'production' : 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Issued per environment from Settings -> API Keys in your Smile&Pay merchant
    | dashboard. Sandbox keys will not authenticate against production.
    |
    | The secret is a password. It belongs in .env on your server and nowhere
    | else — never in a repository, never in a frontend bundle, never in a mobile
    | app. ZB's own card sample calls the API from browser JavaScript with both
    | credentials inline; do not copy that pattern. Anyone who opens devtools
    | would be able to initiate payments as you.
    |
    */

    'environments' => [

        'sandbox' => [
            'key' => env('SMILEPAY_SANDBOX_KEY', env('SMILEPAY_KEY')),
            'secret' => env('SMILEPAY_SANDBOX_SECRET', env('SMILEPAY_SECRET')),
            'base_url' => env(
                'SMILEPAY_SANDBOX_URL',
                'https://zbnet.zb.co.zw/wallet_sandbox_api/payments-gateway'
            ),
        ],

        'production' => [
            'key' => env('SMILEPAY_PRODUCTION_KEY', env('SMILEPAY_KEY')),
            'secret' => env('SMILEPAY_PRODUCTION_SECRET', env('SMILEPAY_SECRET')),
            'base_url' => env(
                'SMILEPAY_PRODUCTION_URL',
                'https://zbnet.zb.co.zw/wallet_gateway/payments-gateway'
            ),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | USD (ISO 840) or ZWG (ISO 924). The package sends the numeric code on the
    | wire and keeps the readable one in your code and database.
    |
    */

    'default_currency' => env('SMILEPAY_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Request defaults
    |--------------------------------------------------------------------------
    |
    | Applied to any payment request that does not set them. Setting result_url
    | here is usually right — it is the same endpoint for every payment, and
    | leaving it null means every call site has to remember it.
    |
    | Leave result_url null to use the package's own callback route.
    |
    */

    'defaults' => [
        'return_url' => env('SMILEPAY_RETURN_URL'),
        'result_url' => env('SMILEPAY_RESULT_URL'),
        'item_name' => env('SMILEPAY_ITEM_NAME'),
        'item_description' => env('SMILEPAY_ITEM_DESCRIPTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook (callback) handling
    |--------------------------------------------------------------------------
    |
    | Smile&Pay callbacks are UNSIGNED. There is no HMAC, no shared secret and
    | no way to prove a POST came from ZB. The URL is not secret either — it is
    | echoed back inside the callback body.
    |
    | So this package never trusts a callback body. Each one triggers an
    | authenticated status check, and only that answer changes state. The extra
    | round trip is what stops a stranger's POST from marking an order paid.
    |
    */

    'webhook' => [

        // Register the built-in callback route. Turn off to handle callbacks in
        // your own controller — call CallbackHandler from it, do not skip it.
        'enabled' => env('SMILEPAY_WEBHOOK_ENABLED', true),

        'path' => env('SMILEPAY_WEBHOOK_PATH', 'smilepay/callback'),

        // Middleware for the callback route. 'api' deliberately excludes CSRF
        // and session state — ZB posts machine-to-machine.
        'middleware' => ['api'],

        /*
         | Leave this true.
         |
         | Setting it to false makes the package believe whatever the callback
         | body says, which means anyone who can POST to your URL can mark any
         | order paid. It exists only for local development against a stubbed
         | gateway, and it is refused outright in production.
         */
        'verify_with_status_check' => env('SMILEPAY_VERIFY_CALLBACKS', true),

        /*
         | Optional source-IP allowlist. Plain IPs or CIDR ranges.
         | Ask your ZB integration contact for their egress range.
         |
         | Behind a load balancer, configure TrustProxies first or this will
         | see the balancer's IP instead of the real client.
         |
         |   SMILEPAY_ALLOWED_IPS="196.27.0.0/16,41.79.0.0/18"
         */
        'allowed_ips' => array_values(array_filter(
            explode(',', (string) env('SMILEPAY_ALLOWED_IPS', ''))
        )),

        /*
         | Optional secret appended to the callback path, giving a URL only you
         | and ZB know:  /smilepay/callback/9f2c...  Cheap defence in depth,
         | since a URL is not a credential — but it does stop opportunistic
         | scanning of the well-known path.
         */
        'secret_path' => env('SMILEPAY_WEBHOOK_SECRET_PATH'),

        // Keep an append-only record of every callback received.
        'store_events' => env('SMILEPAY_STORE_WEBHOOK_EVENTS', true),

    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | The local transaction log. Written before the gateway is called, so a
    | timeout leaves a reconcilable record rather than an invisible payment.
    |
    | Disabling it removes callback deduplication and reconciliation. Only do
    | that if you keep an equivalent ledger elsewhere.
    |
    */

    'database' => [
        'enabled' => env('SMILEPAY_PERSIST', true),
        'connection' => env('SMILEPAY_DB_CONNECTION'),
        'transactions_table' => 'smilepay_transactions',
        'webhook_events_table' => 'smilepay_webhook_events',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP transport
    |--------------------------------------------------------------------------
    */

    'http' => [

        // Total request budget. Wallet initiations can be slow — the gateway
        // often waits on the mobile network before answering.
        'timeout' => (float) env('SMILEPAY_TIMEOUT', 30),

        'connect_timeout' => (float) env('SMILEPAY_CONNECT_TIMEOUT', 10),

        /*
         | TLS verification. Never disable this in production — the package
         | refuses to boot if you try.
         |
         | zb.co.zw hosts have been observed serving an incomplete certificate
         | chain, which some clients reject. The fix is to install the missing
         | intermediate on your server (or point Guzzle at an updated CA
         | bundle), not to stop verifying: without verification, anyone on the
         | path can read your API secret and rewrite payment instructions.
         */
        'verify_ssl' => env('SMILEPAY_VERIFY_SSL', true),

        'user_agent' => 'aaronkatema-laravel-smilepay/1.0',

    ],

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Applies to idempotent calls only — status checks and other GETs.
    | Payment initiation is NEVER retried automatically: the gateway may have
    | already created the transaction and prompted the customer, and a blind
    | retry risks charging them twice. Recovery for a failed initiation is
    | reconciliation, not repetition.
    |
    | Backoff is exponential with full jitter, so a fleet of servers coming out
    | of a ZB outage does not stampede it straight back down.
    |
    */

    'retry' => [
        'attempts' => (int) env('SMILEPAY_RETRY_ATTEMPTS', 3),
        'base_delay_ms' => (int) env('SMILEPAY_RETRY_BASE_MS', 250),
        'max_delay_ms' => (int) env('SMILEPAY_RETRY_MAX_MS', 4000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Request and response logging. Secrets, card numbers, CVVs and OTPs are
    | stripped, and phone numbers and emails masked, before anything is written.
    |
    */

    'log_requests' => env('SMILEPAY_LOG_REQUESTS', true),
    'log_channel' => env('SMILEPAY_LOG_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | The safety net. A wallet push nobody answered, a callback that never
    | arrived, a poll job killed mid-deploy — all leave a transaction open
    | forever unless something goes looking.
    |
    | Schedule it:  Schedule::command('smilepay:reconcile')->everyFiveMinutes();
    |
    */

    'reconciliation' => [
        'stale_after_seconds' => (int) env('SMILEPAY_STALE_AFTER', 300),
        'batch_size' => (int) env('SMILEPAY_RECONCILE_BATCH', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment methods offered
    |--------------------------------------------------------------------------
    |
    | Restrict what your checkout UI shows. Merchants are enabled per rail on
    | ZB's side; offering one you are not approved for produces a decline the
    | customer cannot act on.
    |
    */

    'methods' => [
        PaymentMethod::ECOCASH->value,
        PaymentMethod::ONEMONEY->value,
        PaymentMethod::INNBUCKS->value,
        PaymentMethod::WALLETPLUS->value,
        PaymentMethod::OMARI->value,
        PaymentMethod::CARD->value,
    ],

    /*
    |--------------------------------------------------------------------------
    | Status vocabulary overrides
    |--------------------------------------------------------------------------
    |
    | Maps raw gateway status strings onto this package's TransactionStatus
    | cases. The built-in vocabulary covers the documented values; add entries
    | here if ZB introduces a status the package does not yet recognise.
    |
    | An unmapped status resolves to "unknown", never to "paid". That asymmetry
    | is intentional — a false paid gives goods away, a false unknown just
    | triggers another status check.
    |
    |   'PENDING_3DS' => 'awaiting_3ds',
    |
    */

    'status_map' => [],

];
