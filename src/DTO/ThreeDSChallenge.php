<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\DTO;

use JsonSerializable;

/**
 * A 3-D Secure challenge returned by the MPGS card endpoint.
 *
 * ZB hands back a self-submitting HTML form (`redirectHtml`) that posts the
 * customer to their bank's Access Control Server, plus the raw `acsUrl` and
 * `cReq` for callers who would rather build the form themselves.
 *
 * Prefer `acsUrl` + `cReq`. Injecting a third party's HTML into your page and
 * then hand-executing the `<script>` inside it — which is what ZB's own sample
 * does, because browsers correctly refuse to run scripts inserted via
 * `innerHTML` — means any change on their side executes in your origin. Posting
 * the two values to a form you control gets the customer to the same ACS with
 * none of that exposure.
 */
final readonly class ThreeDSChallenge implements JsonSerializable
{
    public function __construct(
        public ?string $acsUrl = null,
        public ?string $cReq = null,
        public ?string $redirectHtml = null,
    ) {}

    /**
     * Extract a challenge from a gateway response, if one is present.
     *
     * @param  array<string, mixed>  $body
     */
    public static function fromResponse(array $body): ?self
    {
        $customized = $body['customizedHtml'] ?? $body['customized_html'] ?? null;
        $acsUrl = null;
        $cReq = null;

        if (is_array($customized)) {
            $threeDs = $customized['3ds2'] ?? $customized['3ds'] ?? null;

            if (is_array($threeDs)) {
                $acsUrl = self::str($threeDs, 'acsUrl', 'acs_url');
                $cReq = self::str($threeDs, 'cReq', 'creq', 'c_req');
            }
        }

        $redirectHtml = self::str($body, 'redirectHtml', 'redirect_html');

        if ($acsUrl === null && $cReq === null && $redirectHtml === null) {
            return null;
        }

        return new self($acsUrl, $cReq, $redirectHtml);
    }

    /**
     * True when the challenge can be rendered from structured values rather
     * than by executing ZB's HTML. Always check this first.
     */
    public function hasStructuredChallenge(): bool
    {
        return $this->acsUrl !== null && $this->cReq !== null;
    }

    /**
     * Build the ACS form yourself, from values you control.
     *
     * Renders a minimal auto-submitting form targeting the issuer's ACS. No
     * third-party script executes in your origin. Point the form at a
     * sandboxed iframe by passing its name as `$target`.
     */
    public function toSafeHtml(string $target = '_self'): ?string
    {
        if (! $this->hasStructuredChallenge()) {
            return null;
        }

        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            '<form id="smilepay-3ds" method="POST" action="%s" target="%s">'
            .'<input type="hidden" name="creq" value="%s">'
            .'</form>'
            .'<script>document.getElementById("smilepay-3ds").submit();</script>',
            $e($this->acsUrl ?? ''),
            $e($target),
            $e($this->cReq ?? '')
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'acs_url' => $this->acsUrl,
            'creq' => $this->cReq,
            'has_structured_challenge' => $this->hasStructuredChallenge(),
            // The raw HTML is deliberately excluded from the array form so it
            // does not end up in a log or an API response by accident.
            'has_redirect_html' => $this->redirectHtml !== null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function str(array $source, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
