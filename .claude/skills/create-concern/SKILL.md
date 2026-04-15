---
description: Create a new Concern (trait) for the Billable model in src/Concerns/
argument-hint: "[TraitName]"
---

# Create Concern

1. Place in `src/Concerns/`, include via `Billable` trait
2. Single responsibility, delegates to `app(GatewayProvider::class)`
3. No direct HTTP calls