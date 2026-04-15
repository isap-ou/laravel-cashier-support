---
description: Create a new DTO (Data Transfer Object) using spatie/laravel-data in src/DTO/
argument-hint: "[DtoName]"
---

# Create DTO

1. Extend `Spatie\LaravelData\Data` in `src/DTO/`, namespace `Isapp\CashierSupport\DTO`
2. Properties via typed `public` constructor parameters (Data handles readonly)
3. Use Spatie Data casts and transformers for complex types
4. Money — `int` (cents), currency — `Currency` enum
5. Nested DTOs — type-hint other Data classes, Spatie handles nesting automatically