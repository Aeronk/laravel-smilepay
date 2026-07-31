<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Http\Middleware;

use AaronKatema\SmilePay\Events\SuspiciousCallbackDetected;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Optional source-IP allowlist for the callback endpoint.
 *
 * Because Smile&Pay callbacks carry no signature, network origin is the only
 * cheap authentication available. It is not strong — IPs can be spoofed in
 * principle, and a proxy header can be forged if you trust the wrong one — but
 * it raises the bar from "anyone with the URL" to "anyone who can source
 * traffic from ZB's range", and it costs nothing.
 *
 * Ask your ZB integration contact for their egress range and set
 * `SMILEPAY_ALLOWED_IPS`. Empty means disabled, which is the default so the
 * package works out of the box.
 *
 * Behind a load balancer, configure Laravel's `TrustProxies` first — otherwise
 * `$request->ip()` returns the balancer and this either blocks everything or,
 * worse, trusts a forged `X-Forwarded-For`.
 */
final class AllowSmilePayIps
{
    public function __construct(
        private readonly ?Dispatcher $events = null,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowed */
        $allowed = array_values(array_filter(
            (array) config('smilepay.webhook.allowed_ips', []),
            static fn ($value) => is_string($value) && trim($value) !== ''
        ));

        if ($allowed === []) {
            return $next($request);
        }

        $ip = $request->ip();

        if ($ip !== null && $this->matches($ip, $allowed)) {
            return $next($request);
        }

        $this->events?->dispatch(new SuspiciousCallbackDetected(
            payload: [],
            reason: sprintf('callback from %s is outside the configured allowlist', $ip ?? 'unknown'),
            sourceIp: $ip,
        ));

        // 404 rather than 403: a forbidden response confirms the endpoint
        // exists and is worth probing further.
        throw new NotFoundHttpException;
    }

    /**
     * @param  list<string>  $allowed  Plain IPs or CIDR ranges.
     */
    private function matches(string $ip, array $allowed): bool
    {
        foreach ($allowed as $entry) {
            $entry = trim($entry);

            if (! str_contains($entry, '/')) {
                if (hash_equals($entry, $ip)) {
                    return true;
                }

                continue;
            }

            if ($this->inCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        if ($subnet === null || $bits === null || ! is_numeric($bits)) {
            return false;
        }

        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        // Mixing families (an IPv6 client against an IPv4 rule) can never match.
        if (strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $bits = (int) $bits;
        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && ! hash_equals(
            substr($subnetBinary, 0, $wholeBytes),
            substr($ipBinary, 0, $wholeBytes)
        )) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($ipBinary[$wholeBytes]) & $mask) === (ord($subnetBinary[$wholeBytes]) & $mask);
    }
}
