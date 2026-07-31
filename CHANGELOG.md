# Changelog

All notable changes to `aaronkatema/laravel-smilepay` are documented here.
This project follows [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-07-26

Initial release. Built against the Smile&Pay API documentation as published at
`smileandpay.zb.co.zw/documentation`.

### Payments
- Standard (hosted) Checkout via `POST /payments/initiate-transaction`
- Express Checkout for EcoCash, OneMoney, InnBucks, SmileCash (WalletPlus),
  O'mari and Visa/Mastercard, each on its own endpoint
- Two-leg OTP confirmation for SmileCash and O'mari, including the O'mari
  requirement to echo the mobile number on leg 2
- 3-D Secure challenge parsing for card payments, with a safe form builder that
  avoids executing the gateway's HTML in your origin
- InnBucks payment codes and app deep links
- Transaction status check and cancellation

### Security
- Mandatory server-to-server verification of every inbound callback. Unsigned
  callback bodies are never allowed to change state
- `SuspiciousCallbackDetected` event for callbacks that disagree with the gateway
- Optional source-IP allowlist (CIDR aware) and secret webhook path segment
- Secrets, PANs, CVVs and OTPs redacted from all logs; contact details masked
- `CardDetails` resists `var_dump`, `dd()`, `json_encode`, serialisation and
  exception traces
- TLS verification cannot be disabled in production

### Correctness
- Integer minor-unit money arithmetic
- ISO 4217 numeric currency codes on the wire, alphabetic in your code
- Application-level `responseCode` checked independently of HTTP status
- Unknown gateway statuses degrade to `UNKNOWN`, never to `PAID`
- Terminal states never walked backwards; settled events fire exactly once
- Payment initiation never retried automatically; idempotent calls retried with
  exponential backoff and full jitter

### Infrastructure
- Eloquent transaction log and append-only webhook event log
- `smilepay:reconcile` and `smilepay:status` Artisan commands
- Lifecycle events: `PaymentInitiated`, `PaymentSucceeded`, `PaymentFailed`,
  `PaymentCancelled`, `PaymentStatusChanged`, `WebhookReceived`
- `SmilePay::fake()` test double covering every rail, the OTP legs, polling and
  the callback pipeline
- Laravel 11, 12 and 13; PHP 8.2+
