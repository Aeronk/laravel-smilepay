<?php

declare(strict_types=1);

use AaronKatema\SmilePay\Enums\TransactionStatus;

it('maps documented gateway statuses', function (string $raw, TransactionStatus $expected): void {
    expect(TransactionStatus::fromGateway($raw))->toBe($expected);
})->with([
    ['PAID', TransactionStatus::PAID],
    ['paid', TransactionStatus::PAID],
    ['SUCCESS', TransactionStatus::PAID],
    ['PENDING', TransactionStatus::PENDING],
    ['PENDING_3DS', TransactionStatus::AWAITING_3DS],
    ['AUTHENTICATION_REQUIRED', TransactionStatus::AWAITING_3DS],
    ['FAILED', TransactionStatus::FAILED],
    ['CANCELLED', TransactionStatus::CANCELLED],
    ['partially-refunded', TransactionStatus::PARTIALLY_REFUNDED],
]);

it('degrades an unknown status to UNKNOWN, never to PAID', function (): void {
    // The asymmetry that matters: a false PAID gives goods away, a false
    // UNKNOWN just triggers another status check.
    expect(TransactionStatus::fromGateway('SOMETHING_NEW'))->toBe(TransactionStatus::UNKNOWN)
        ->and(TransactionStatus::fromGateway(null))->toBe(TransactionStatus::UNKNOWN)
        ->and(TransactionStatus::fromGateway(''))->toBe(TransactionStatus::UNKNOWN)
        ->and(TransactionStatus::UNKNOWN->isSuccessful())->toBeFalse()
        ->and(TransactionStatus::UNKNOWN->isFinal())->toBeFalse();
});

it('honours a config status override', function (): void {
    config()->set('smilepay.status_map', ['SETTLED_OK' => 'paid']);

    expect(TransactionStatus::fromGateway('SETTLED_OK'))->toBe(TransactionStatus::PAID);
});

it('knows which states are final', function (): void {
    expect(TransactionStatus::PAID->isFinal())->toBeTrue()
        ->and(TransactionStatus::FAILED->isFinal())->toBeTrue()
        ->and(TransactionStatus::PROCESSING->isFinal())->toBeFalse()
        ->and(TransactionStatus::AWAITING_OTP->isFinal())->toBeFalse()
        ->and(TransactionStatus::AWAITING_OTP->needsCustomerAction())->toBeTrue();
});
