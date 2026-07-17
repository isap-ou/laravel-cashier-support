<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Testing\GatewayConformanceTestCase;
use Isapp\CashierSupport\Tests\Fixtures\PartialGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;

/**
 * The suite against a partial BaseGateway driver — some methods overridden, some refused, some
 * capabilities declared. This is the realistic middle a real driver occupies, and the exact shape
 * the earlier "unsupported ⇒ throws" coupling would have false-failed (it declares no Subscriptions
 * capability yet cancelSubscription() returns). Green here is the proof the contract is sound.
 */
class PartialGatewayConformanceTest extends GatewayConformanceTestCase
{
    protected function gateway(): GatewayProvider
    {
        return new PartialGateway;
    }

    protected function billable(): Model
    {
        return new User;
    }
}
