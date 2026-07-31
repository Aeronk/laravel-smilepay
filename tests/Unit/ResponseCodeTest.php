<?php

declare(strict_types=1);

use AaronKatema\SmilePay\Support\ResponseCode;

it('treats only 00 as success', function (): void {
    expect(ResponseCode::isSuccess('00'))->toBeTrue()
        ->and(ResponseCode::isSuccess('0'))->toBeTrue()
        ->and(ResponseCode::isSuccess(0))->toBeTrue()
        ->and(ResponseCode::isSuccess('01'))->toBeFalse()
        ->and(ResponseCode::isSuccess('51'))->toBeFalse()
        ->and(ResponseCode::isSuccess(null))->toBeFalse();
});

it('prefers the gateway message over the built-in table', function (): void {
    expect(ResponseCode::describe('51', 'Not enough balance'))->toBe('Not enough balance')
        ->and(ResponseCode::describe('51'))->toBe('Insufficient funds')
        ->and(ResponseCode::describe('XX'))->toContain('XX');
});

it('marks only switch failures as retryable', function (): void {
    expect(ResponseCode::isRetryable('91'))->toBeTrue()
        ->and(ResponseCode::isRetryable('96'))->toBeTrue()
        // Retrying a decline annoys the customer and trips fraud rules.
        ->and(ResponseCode::isRetryable('51'))->toBeFalse()
        ->and(ResponseCode::isRetryable('05'))->toBeFalse();
});
