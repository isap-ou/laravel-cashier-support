---
description: Create a new contract (interface) in src/Contracts/
argument-hint: "[InterfaceName]"
---

# Create Contract

1. Place in `src/Contracts/`, namespace `Isapp\CashierSupport\Contracts`
2. Full PHPDoc: @param, @return, @throws
3. Method names — strictly from `laravel/cashier-stripe` (docs: https://laravel.com/docs/12.x/billing)
4. No Stripe analogue → `@throws UnsupportedOperationException` + `@since` annotation