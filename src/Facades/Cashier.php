<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Facades;

use Illuminate\Support\Facades\Facade;
use Isapp\CashierSupport\CashierManager;

/**
 * @method static \Isapp\CashierSupport\Contracts\GatewayProvider provider(?string $driver = null)
 * @method static bool supports(\Isapp\CashierSupport\Enums\Capability $capability, ?string $driver = null)
 * @method static void ensureSupports(\Isapp\CashierSupport\Enums\Capability $capability, ?string $driver = null)
 * @method static mixed driver(?string $driver = null)
 * @method static \Isapp\CashierSupport\CashierManager extend(string $driver, \Closure $callback)
 *
 * @see CashierManager
 */
class Cashier extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CashierManager::class;
    }
}
