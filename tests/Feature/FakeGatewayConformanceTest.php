<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Testing\FakeGateway;
use Isapp\CashierSupport\Testing\GatewayConformanceTestCase;
use Isapp\CashierSupport\Tests\Fixtures\User;

/**
 * The shipped conformance suite, exercised against a fully-capable FakeGateway: every operation
 * is supported, so every one must return its declared type. This is the suite proving itself.
 */
class FakeGatewayConformanceTest extends GatewayConformanceTestCase
{
    protected function gateway(): GatewayProvider
    {
        return new FakeGateway(Capability::cases());
    }

    protected function billable(): Model
    {
        return new User;
    }
}
