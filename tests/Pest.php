<?php

declare(strict_types=1);

use AaronKatema\SmilePay\Tests\TestCase;

// Applied to Unit as well as Feature: a few value objects consult package
// config (the status vocabulary override), and they should be exercised the
// way the framework will actually call them.
uses(TestCase::class)->in('Feature', 'Unit');
