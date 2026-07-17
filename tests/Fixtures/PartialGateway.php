<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Isapp\CashierSupport\DTO\Payment;
use Isapp\CashierSupport\DTO\Subscription;
use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Enums\PaymentStatus;
use Isapp\CashierSupport\Enums\SubscriptionStatus;
use Isapp\CashierSupport\Gateway\BaseGateway;
use Money\Currency;

/**
 * A realistic in-between gateway: it implements SOME operations and refuses the rest, the shape a
 * real driver actually is. It overrides charge() (so it supports Charges) and cancelSubscription()
 * — but NOT newSubscription(), so Subscriptions (which needs both) stays unsupported even though
 * cancellation works.
 *
 * That last fact is the point: supports(Subscriptions) is false while cancelSubscription() returns
 * a DTO. A conformance suite that asserted "unsupported capability ⇒ the method throws" would
 * false-fail this gateway; the sound suite only requires each operation to be *answerable* and each
 * *declared* capability to work, both of which hold here.
 */
class PartialGateway extends BaseGateway
{
    public function charge(Model $billable, int $amount, string $paymentMethod, array $options = []): Payment
    {
        return new Payment(id: 'pay_partial', amount: $amount, currency: new Currency('EUR'), status: PaymentStatus::Succeeded);
    }

    public function cancelSubscription(Model $billable, string $type = 'default'): Subscription
    {
        return new Subscription(id: 'sub_partial', type: $type, status: SubscriptionStatus::Canceled);
    }

    /**
     * @return array<int, Capability>
     */
    protected function declaredCapabilities(): array
    {
        return [];
    }
}
