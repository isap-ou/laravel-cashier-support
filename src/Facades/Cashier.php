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
 * @method static void macro(string $name, callable|object $macro)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 * @method static void useModels(string $driver, array<string, class-string<\Illuminate\Database\Eloquent\Model>> $models)
 * @method static class-string<\Isapp\CashierSupport\Models\Subscription> subscriptionModel(?string $driver = null)
 * @method static class-string<\Isapp\CashierSupport\Models\SubscriptionItem> subscriptionItemModel(?string $driver = null)
 * @method static class-string<\Isapp\CashierSupport\Models\Invoice> invoiceModel(?string $driver = null)
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
