<?php

declare(strict_types=1);

namespace AaronKatema\SmilePay\Facades;

use AaronKatema\SmilePay\DTO\CardDetails;
use AaronKatema\SmilePay\DTO\PaymentRequest;
use AaronKatema\SmilePay\DTO\PaymentResult;
use AaronKatema\SmilePay\DTO\TransactionSnapshot;
use AaronKatema\SmilePay\Enums\PaymentMethod;
use AaronKatema\SmilePay\Models\SmilePayTransaction;
use AaronKatema\SmilePay\SmilePay as SmilePayManager;
use AaronKatema\SmilePay\Support\Msisdn;
use AaronKatema\SmilePay\Testing\SmilePayFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PaymentResult checkout(PaymentRequest $request)
 * @method static PaymentResult express(PaymentRequest $request, ?CardDetails $card = null)
 * @method static PaymentResult ecocash(PaymentRequest $request)
 * @method static PaymentResult oneMoney(PaymentRequest $request)
 * @method static PaymentResult innbucks(PaymentRequest $request)
 * @method static PaymentResult smileCash(PaymentRequest $request)
 * @method static PaymentResult omari(PaymentRequest $request)
 * @method static PaymentResult card(PaymentRequest $request, CardDetails $card)
 * @method static PaymentResult confirmOtp(string $transactionReference, string $otp, PaymentMethod|string $method, Msisdn|string|null $mobile = null, ?string $orderReference = null)
 * @method static TransactionSnapshot verify(string $orderReference)
 * @method static TransactionSnapshot status(string $orderReference)
 * @method static TransactionSnapshot poll(string $orderReference, int $timeoutSeconds = 120, int $intervalSeconds = 5)
 * @method static TransactionSnapshot cancel(string $orderReference)
 * @method static SmilePayTransaction|null transaction(string $orderReference)
 * @method static array reconcile(int $olderThanSeconds = 300, int $limit = 100)
 *
 * @see SmilePayManager
 */
final class SmilePay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SmilePayManager::class;
    }

    /**
     * Swap the gateway for an in-memory fake.
     *
     * Every rail, the OTP flow, polling and the callback pipeline all work
     * against the fake, so a checkout can be tested end to end without a
     * network call or a sandbox account:
     *
     *     SmilePay::fake()->willSucceed('ORDER-1');
     *     $this->post('/checkout', [...]);
     *     SmilePay::fake()->assertPaid('ORDER-1');
     */
    public static function fake(): SmilePayFake
    {
        $fake = new SmilePayFake(
            app(\AaronKatema\SmilePay\Support\Config::class),
            app(\AaronKatema\SmilePay\Contracts\TransactionStore::class),
            app(\Illuminate\Contracts\Events\Dispatcher::class),
        );

        static::swap($fake);

        // Also replace the container binding. Controllers usually type-hint
        // SmilePay rather than reaching for the facade, and swapping only the
        // facade would leave those paths talking to the real gateway — a fake
        // that silently does nothing is worse than no fake.
        app()->instance(SmilePayManager::class, $fake);
        app()->forgetInstance(\AaronKatema\SmilePay\Webhooks\CallbackHandler::class);

        return $fake;
    }
}
