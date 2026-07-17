<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Contracts\GatewayProvider;
use Isapp\CashierSupport\Testing\GatewayConformanceTestCase;
use Isapp\CashierSupport\Tests\Fixtures\MinimalGateway;
use Isapp\CashierSupport\Tests\Fixtures\User;

/**
 * The same suite against a gateway that supports nothing: every operation is unsupported, so every
 * one must refuse with UnsupportedOperationException rather than returning or fatally erroring.
 * This is the driver-side of the drop-in guarantee — a gateway that cannot do X still answers X.
 */
class RefusingGatewayConformanceTest extends GatewayConformanceTestCase
{
    protected function gateway(): GatewayProvider
    {
        return new MinimalGateway;
    }

    protected function billable(): Model
    {
        return new User;
    }
}
