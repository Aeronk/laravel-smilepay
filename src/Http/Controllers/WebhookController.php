<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Http\Controllers;

use AaronKatema\SmilePay\Webhooks\CallbackHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Smile&Pay payment callbacks.
 *
 * Thin by design — parse, delegate, acknowledge. All judgement lives in
 * CallbackHandler, which is a plain service and therefore testable without an
 * HTTP kernel.
 *
 * Always responds 200. ZB retries anything else, and there is no callback this
 * endpoint can receive that a retry would improve: a forged one stays forged, a
 * malformed one stays malformed, and a genuine one whose verification failed is
 * already queued for the reconciliation pass.
 */
final class WebhookController
{
    public function __invoke(Request $request, CallbackHandler $handler): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all() ?: $request->all();

        $outcome = $handler->handle($payload, $request->ip());

        return new JsonResponse([
            'received' => true,
            'decision' => $outcome->decision,
        ], 200);
    }
}
