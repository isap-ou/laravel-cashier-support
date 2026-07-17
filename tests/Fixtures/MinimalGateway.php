<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Fixtures;

use Isapp\CashierSupport\Enums\Capability;
use Isapp\CashierSupport\Gateway\BaseGateway;

/**
 * A gateway that does nothing at all — and still loads, still substitutes, still answers
 * every method a driver must answer.
 *
 * This fixture IS #28's acceptance criteria. It overrides nothing, so every operation is
 * the inherited refusal and every derivable capability is false without a word being
 * declared. When support adds a method to a contract, this class does not change and does
 * not break; if it ever does, the change that caused it took the BC guarantee away.
 *
 * **Its existence is the guarantee — do not replace it with a test that asserts the same
 * thing.** A concrete subclass of BaseGateway cannot load unless every contract method has
 * a real body there: omit one, or leave it `abstract`, and PHP refuses the class outright
 * ("contains 1 abstract method and must therefore be declared abstract"). That fires in
 * setUp(), before any assertion runs. A sweep over GatewayProvider comparing it to
 * BaseGateway was written first and deleted: both of its assertions were unreachable,
 * because the fatal always won. The type system is the better test here; this class is how
 * the suite invokes it.
 *
 * FakeGateway is the other end of the same scale: it implements everything and declares
 * nothing derivable either. Between them, the two prove the mechanism reads the code
 * rather than a list.
 */
class MinimalGateway extends BaseGateway
{
    /**
     * Not `[]` because nothing is supported — `[]` because this gateway declares nothing.
     * The distinction matters: the eight intent capabilities are the only ones a driver
     * can assert, and asserting none is a real answer.
     *
     * @return array<int, Capability>
     */
    protected function declaredCapabilities(): array
    {
        return [];
    }
}
