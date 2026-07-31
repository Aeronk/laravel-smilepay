<?php

declare(strict_types=1);

use AaronKatema\SmilePay\Http\Controllers\WebhookController;
use AaronKatema\SmilePay\Http\Middleware\AllowSmilePayIps;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Smile&Pay callback route
|--------------------------------------------------------------------------
|
| Registered only when smilepay.webhook.enabled is true.
|
| Deliberately outside the 'web' middleware group: ZB posts machine-to-machine
| with no session and no CSRF token, and routing it through 'web' would start a
| session per callback for no reason.
|
| Set SMILEPAY_WEBHOOK_SECRET_PATH to append a secret segment to the URL, so
| the endpoint is not sitting at a guessable path. That is defence in depth, not
| authentication — the real guarantee is that CallbackHandler verifies every
| callback against the gateway before believing a word of it.
|
*/

$path = trim((string) config('smilepay.webhook.path', 'smilepay/callback'), '/');
$secret = config('smilepay.webhook.secret_path');

if (is_string($secret) && trim($secret) !== '') {
    $path .= '/'.trim($secret, '/');
}

/** @var array<int, string> $middleware */
$middleware = (array) config('smilepay.webhook.middleware', ['api']);
$middleware[] = AllowSmilePayIps::class;

Route::post($path, WebhookController::class)
    ->middleware($middleware)
    ->name('smilepay.callback');
