<?php

declare(strict_types=1);

namespace Isapp\CashierSupport;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\Concerns\HandlesCheckout;
use Isapp\CashierSupport\Concerns\HandlesTaxes;
use Isapp\CashierSupport\Concerns\ManagesCustomer;
use Isapp\CashierSupport\Concerns\ManagesInvoices;
use Isapp\CashierSupport\Concerns\ManagesPaymentMethods;
use Isapp\CashierSupport\Concerns\ManagesSubscriptions;
use Isapp\CashierSupport\Concerns\PerformsCharges;

/**
 * Meta-trait that exposes the full billing API on a model.
 *
 * Apply it to any Eloquent model to make it billable. Every operation is
 * delegated to the bound GatewayProvider and gated by the provider's
 * declared capabilities.
 *
 * @phpstan-require-extends Model
 */
trait Billable
{
    use HandlesCheckout;
    use HandlesTaxes;
    use ManagesCustomer;
    use ManagesInvoices;
    use ManagesPaymentMethods;
    use ManagesSubscriptions;
    use PerformsCharges;
}
