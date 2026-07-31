<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\DTO;

use AaronKatema\SmilePay\Exceptions\InvalidPaymentRequestException;
use AaronKatema\SmilePay\Support\Msisdn;
use JsonSerializable;

/**
 * The paying customer.
 *
 * Smile&Pay treats every customer field as optional at the API level, but the
 * chosen rail does not: a wallet push has nowhere to go without a mobile
 * number. That dependency is enforced on PaymentRequest rather than here, so a
 * Customer can be built once from your users table and reused across rails.
 *
 * Name is stored split because the API takes `firstName` and `lastName`
 * separately; `make()` will split a single full-name string on your behalf.
 */
final readonly class Customer implements JsonSerializable
{
    public function __construct(
        public ?Msisdn $msisdn = null,
        public ?string $email = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
    ) {
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw InvalidPaymentRequestException::invalidEmail($email);
        }
    }

    /**
     * Ergonomic constructor. Parses a loose mobile number and, when `$lastName`
     * is omitted, splits `$firstName` on the first space so that passing a
     * single "Aaron Katema" string does the obvious thing.
     */
    public static function make(
        Msisdn|string|null $msisdn = null,
        ?string $email = null,
        ?string $firstName = null,
        ?string $lastName = null,
    ): self {
        $first = self::blankToNull($firstName);
        $last = self::blankToNull($lastName);

        if ($last === null && $first !== null && str_contains($first, ' ')) {
            [$first, $last] = explode(' ', $first, 2);
        }

        return new self(
            msisdn: $msisdn === null ? null : Msisdn::parse($msisdn),
            email: self::blankToNull($email),
            firstName: self::blankToNull($first),
            lastName: self::blankToNull($last),
        );
    }

    /**
     * Build from a loose array, tolerating the key names merchants commonly use.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = static function (array $data, string ...$keys): ?string {
            foreach ($keys as $key) {
                $value = $data[$key] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }

            return null;
        };

        return self::make(
            msisdn: $string($data, 'msisdn', 'mobilePhoneNumber', 'mobileNumber', 'phone', 'mobile'),
            email: $string($data, 'email'),
            firstName: $string($data, 'firstName', 'first_name', 'name'),
            lastName: $string($data, 'lastName', 'last_name'),
        );
    }

    public function withMsisdn(Msisdn|string $msisdn): self
    {
        return new self(Msisdn::parse($msisdn), $this->email, $this->firstName, $this->lastName);
    }

    public function withEmail(string $email): self
    {
        return new self($this->msisdn, $email, $this->firstName, $this->lastName);
    }

    public function withName(string $firstName, ?string $lastName = null): self
    {
        $named = self::make(null, null, $firstName, $lastName);

        return new self($this->msisdn, $this->email, $named->firstName, $named->lastName);
    }

    public function fullName(): ?string
    {
        $name = trim(($this->firstName ?? '').' '.($this->lastName ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * Field names exactly as Smile&Pay expects them on the initiate endpoint.
     *
     * @return array<string, string>
     */
    public function toGatewayPayload(): array
    {
        return array_filter([
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'email' => $this->email,
            'mobilePhoneNumber' => $this->msisdn?->national(),
        ], static fn (?string $value) => $value !== null && $value !== '');
    }

    /**
     * Redacted representation for the transaction log.
     *
     * A payments table is a high-value target: it links names to phone numbers
     * to amounts. Storing masked contact details keeps the log useful for
     * support ("is this the 077...567 customer?") without turning a database
     * leak into a ready-made marketing list.
     *
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        return array_filter([
            'msisdn' => $this->msisdn?->masked(),
            'email' => $this->email === null ? null : self::maskEmail($this->email),
            'name' => $this->fullName(),
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'msisdn' => $this->msisdn?->international(),
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
        ], static fn ($value) => $value !== null);
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1)
            .str_repeat('*', max(mb_strlen($local) - 1, 1))
            .'@'.$domain;
    }

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
