---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
---

# Coding Standards

- `declare(strict_types=1);` in every .php file
- All DTOs — extend `Spatie\LaravelData\Data` (`spatie/laravel-data`)
- All Enums — `string`-backed `BackedEnum`
- Exceptions extend `CashierException`
- Events — `readonly` properties
- Full PHPDoc on every public method
- One class/interface/enum = one file
- Imports via `use`, never FQCN inline
- 100% test coverage — every public method must have a corresponding test