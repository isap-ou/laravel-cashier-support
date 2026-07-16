# Graph Report - laravel-cashier-support  (2026-07-16)

## Corpus Check
- 140 files · ~30,762 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 942 nodes · 1558 edges · 129 communities (57 shown, 72 thin omitted)
- Extraction: 95% EXTRACTED · 5% INFERRED · 0% AMBIGUOUS · INFERRED: 71 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `cd9a0345`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Customer & Invoice Contracts
- Project Rules & Conventions
- Guarded Subscription Builder
- Checkout Requests & Sessions
- Billable Model Tests
- Payment Method Management
- Customer/Invoice Eloquent Relations
- Fake Gateway Test Double
- Tax Rates & Invoice Concerns
- Local Invoice Records & PDF
- Cashier Driver Manager
- Webhook Handling & Events
- Config, Enums & Labels
- Subscription Management Trait
- Refunds
- Capability Gating Design Docs
- Invoice Builder
- Subscription Query Tests
- Customer Record Persistence
- Fake Subscription Builder
- Capability Gating Tests
- Fake Checkout Session
- Composer Package Metadata
- Runtime Dependencies
- Composer Scripts
- Package Keywords
- Checkout Session DTO
- Pending Price Change Tests
- Dev Dependencies
- Service Provider Registration
- Subscription Renewed Event
- Subscription Canceled Event
- Subscription Created Event
- Subscription Past Due Event
- Subscription Updated Event
- CI Quality & Test Jobs
- Scaffolding Skills
- Laravel Package Auto-Discovery
- Gateway Provider Contract
- PSR-4 Autoload
- Test Autoload
- Package Support Links
- Changelog CI Enforcement
- License Policy Rule
- Manifest
- SubscriptionQuantityTest
- isapp/laravel-cashier-support
- Track Cashier — Upstream Drift Detector
- Capabilities — gate the intent, not the operation
- AGENT.md
- Exceptions — which failures an app is expected to catch
- Smart Stubs — No Custom Workarounds
- coding-standards.md
- constraints.md
- licensing.md
- research-first.md
- sources-of-truth.md
- SKILL.md
- SKILL.md
- SKILL.md
- SKILL.md
- SKILL.md
- SKILL.md
- SKILL.md
- api-researcher agent
- Rule: capabilities gate the intent
- The gate lives in support, not the driver
- Rule: coding standards
- Rule: constraints (no HTTP, no business logic, no floats)
- Rule: exception boundary
- Rule: research first — never trust training data
- Provider-Independent Invoice Generation
- Rule: Smart Stubs — No Custom Workarounds
- UnsupportedOperationException (rule reference)
- Rule: sources of truth (referenced)
- mollie/laravel-cashier-mollie — last resort, not a design authority
- laravel/cashier-paddle — second opinion
- laravel/cashier (Stripe) — primary reference
- Rule: Sources of Truth (priority order)
- Skill: Create Concern
- Skill: Create Contract
- Skill: Create Enum
- Skill: Create Event
- Skill: Create Abstract Model
- Cashier Baseline — mirrored surface (v16.6.0)
- Baseline Manifest — Billable / SubscriptionBuilder / Subscription / Enums / Events
- Baseline Reconciliation Log
- api-researcher agent
- Upstream Symbol → Our File Mapping Table
- Skill: Track Cashier — Upstream Drift Detector
- Subscription knows the period it is paid through
- A capability gates an intent, not an operation
- Gateway customer identity is a first-class record
- The exception boundary is stated, and true
- Subscription item quantity is nullable
- Scheduled price change has somewhere to live
- UnexpectedWebhookEventException (changelog entry)
- Taxes and trials are gated — no silent discard
- Billable meta-trait
- Enums\Capability
- Exceptions\CashierException
- Facades\Cashier
- CashierManager
- mollie/laravel-cashier-mollie (last resort)
- laravel/cashier-paddle (second opinion)
- isapp/laravel-cashier-revolut (driver)
- laravel/cashier-stripe (reference)
- DTO\CheckoutRequest
- tests/Feature/ExceptionBoundaryTest
- Contracts\GatewayProvider
- Builders\GuardedSubscriptionBuilder
- Contracts\SubscriptionBuilder
- Enums\SwapTiming
- Exceptions\UnsupportedOperationException
- Contracts\WebhookHandler

## God Nodes (most connected - your core abstractions)
1. `Cashier` - 43 edges
2. `TestCase` - 37 edges
3. `FakeGateway` - 36 edges
4. `User` - 31 edges
5. `CheckoutRequest` - 30 edges
6. `GranularCapabilitiesTest` - 21 edges
7. `Payment` - 19 edges
8. `CashierException` - 18 edges
9. `isapp/laravel-cashier-support` - 17 edges
10. `CashierManager` - 16 edges

## Surprising Connections (you probably didn't know these)
- `deptrac.yaml — layer boundary rules` --conceptually_related_to--> `Zero business logic, zero HTTP calls`  [INFERRED]
  deptrac.yaml → CLAUDE.md
- `FakeGateway` --references--> `CheckoutRequest`  [EXTRACTED]
  tests/Fixtures/FakeGateway.php → src/DTO/CheckoutRequest.php
- `quality job (PHPStan, Deptrac, Pint)` --shares_data_with--> `Skill: Quality Check`  [INFERRED]
  .github/workflows/tests.yml → .claude/skills/check/SKILL.md
- `tests job (Laravel 11/12/13 × PHP 8.2–8.5 matrix)` --shares_data_with--> `Skill: Quality Check`  [INFERRED]
  .github/workflows/tests.yml → .claude/skills/check/SKILL.md
- `subscriptions()` --calls--> `Cashier`  [INFERRED]
  src/Concerns/ManagesSubscriptions.php → src/Facades/Cashier.php

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Capability gating of caller intent (swap timing + checkout shape)** — concept_capability_enum, concept_swap_timing, concept_checkout_request, concept_guarded_subscription_builder, concept_unsupported_operation_exception, _claude_rules_capabilities, changelog_capability_gates_intent [EXTRACTED 0.90]
- **Billable → CashierManager → GatewayProvider driver resolution** — concept_billable, concept_cashier_manager, concept_cashier_facade, concept_gateway_provider, concept_cashier_revolut [EXTRACTED 0.90]
- **Scaffolding skills for the package's layer structure** — _claude_skills_create_concern_skill_create_concern, _claude_skills_create_contract_skill_create_contract, _claude_skills_create_dto_skill_create_dto, _claude_skills_create_enum_skill_create_enum, _claude_skills_create_event_skill_create_event, _claude_skills_create_model_skill_create_model [EXTRACTED 0.90]
- **Upstream parity flow — sources of truth, drift detection, baseline manifest** — _claude_rules_sources_of_truth_sources_of_truth, _claude_skills_track_cashier_skill_track_cashier, _claude_skills_track_cashier_baseline_baseline, _claude_skills_track_cashier_skill_mapping_table, _claude_rules_sources_of_truth_cashier_stripe [EXTRACTED 0.90]
- **Unsupported-feature policy — capability gate, throw, no workaround** — _claude_rules_smart_stubs_smart_stubs, _claude_rules_smart_stubs_capability_gating, _claude_rules_smart_stubs_unsupportedoperationexception, _claude_skills_create_contract_skill_create_contract [EXTRACTED 0.85]

## Communities (129 total, 72 thin omitted)

### Community 0 - "Customer & Invoice Contracts"
Cohesion: 0.06
Nodes (42): Exception, checkout(), CheckoutSession, Model, asCustomer(), createCustomer(), Customer, Model (+34 more)

### Community 1 - "Project Rules & Conventions"
Cohesion: 0.40
Nodes (15): Zero business logic, zero HTTP calls, deptrac.yaml — layer boundary rules, Deptrac layer: Concerns, Deptrac layer: Contracts, Deptrac layer: DTO, Deptrac layer: Enums, Deptrac layer: Events, Deptrac layer: Exceptions (+7 more)

### Community 2 - "Guarded Subscription Builder"
Cohesion: 0.05
Nodes (19): Facade, MorphOne, cashierDriver(), cashierProvider(), ensureCashierSupports(), Capability, GatewayProvider, asCustomer() (+11 more)

### Community 3 - "Checkout Requests & Sessions"
Cohesion: 0.10
Nodes (9): checkout(), checkoutRequestFromLegacyArguments(), CheckoutSession, CheckoutRequest, Capability, CheckoutMode, Currency, self (+1 more)

### Community 4 - "Billable Model Tests"
Cohesion: 0.10
Nodes (11): Billable, Customer, Model, CustomerRecordsTest, LocalInvoicesGatewayTest, ConcreteCustomer, PriceTaxedUser, SecondaryDriverUser (+3 more)

### Community 5 - "Payment Method Management"
Cohesion: 0.16
Nodes (13): BillingReason, Data, Interval, Customer, Invoice, CarbonImmutable, Currency, PaymentStatus (+5 more)

### Community 6 - "Customer/Invoice Eloquent Relations"
Cohesion: 0.08
Nodes (12): HasMany, HasUuids, Customer, MorphTo, Invoice, BelongsTo, MorphTo, CarbonImmutable (+4 more)

### Community 7 - "Fake Gateway Test Double"
Cohesion: 0.08
Nodes (20): GatewayProvider, PaymentMethodType, addPaymentMethod(), defaultPaymentMethod(), addPaymentMethod(), defaultPaymentMethod(), deletePaymentMethod(), paymentMethods() (+12 more)

### Community 8 - "Tax Rates & Invoice Concerns"
Cohesion: 0.11
Nodes (19): ensureTaxRatesSupported(), priceTaxRates(), taxRates(), downloadInvoice(), findInvoice(), Invoice, Response, charge() (+11 more)

### Community 9 - "Local Invoice Records & PDF"
Cohesion: 0.15
Nodes (18): Builder, InvoiceRecord, PdfBuilder, downloadInvoice(), driverName(), findInvoice(), findInvoiceRecord(), invoiceQuery() (+10 more)

### Community 10 - "Cashier Driver Manager"
Cohesion: 0.15
Nodes (7): Macroable, Manager, CashierManager, Capability, GatewayProvider, InvalidConfigurationException, self

### Community 11 - "Webhook Handling & Events"
Cohesion: 0.09
Nodes (12): parseWebhook(), CarbonImmutable, WebhookPayload, WebhookHandled, WebhookReceived, self, UnexpectedWebhookEventException, self (+4 more)

### Community 12 - "Config, Enums & Labels"
Cohesion: 0.05
Nodes (15): Invoice, Orchestra, Subscription, SubscriptionItem, Model, MigrationsTest, PendingPriceChangeTest, SubscriptionPeriodTest (+7 more)

### Community 13 - "Subscription Management Trait"
Cohesion: 0.22
Nodes (16): MorphMany, cancelSubscription(), cancelSubscriptionNow(), newSubscription(), onGracePeriod(), onTrial(), pauseSubscription(), SubscriptionBuilder (+8 more)

### Community 14 - "Refunds"
Cohesion: 0.16
Nodes (7): RefundReason, CarbonImmutable, Currency, Refund, Model, RefundProcessed, EnumTest

### Community 16 - "Invoice Builder"
Cohesion: 0.26
Nodes (6): InvoiceBuilder, CarbonImmutable, Currency, Invoice, PaymentStatus, self

### Community 17 - "Subscription Query Tests"
Cohesion: 0.20
Nodes (9): Acceptance criteria, Context & Goal, Edge cases, Functional requirements, Non-goals, Open questions, Spec: Complete SubscriptionStatus with Stripe's two unconditional dunning states, Why the fix does not stop at adding two cases (+1 more)

### Community 18 - "Customer Record Persistence"
Cohesion: 0.32
Nodes (9): CustomerNotFoundException, self, customerIdFor(), customerIdOrNull(), customerRecord(), driverName(), persistCustomerId(), Model (+1 more)

### Community 19 - "Fake Subscription Builder"
Cohesion: 0.14
Nodes (9): GuardedSubscriptionBuilder, DateTimeInterface, static, Subscription, SubscriptionBuilder, FakeSubscriptionBuilder, DateTimeInterface, static (+1 more)

### Community 20 - "Capability Gating Tests"
Cohesion: 0.06
Nodes (31): Added, Changed, Changelog, Fixed, [Unreleased], Capabilities, Capabilities gate an intent, not just an operation, Changelog & releases (+23 more)

### Community 22 - "Fake Checkout Session"
Cohesion: 0.27
Nodes (4): CheckoutSession, FakeCheckoutSession, CarbonImmutable, CheckoutMode

### Community 23 - "Composer Package Metadata"
Cohesion: 0.18
Nodes (10): authors, config, sort-packages, description, homepage, license, minimum-stability, name (+2 more)

### Community 24 - "Runtime Dependencies"
Cohesion: 0.18
Nodes (11): require, illuminate/contracts, illuminate/database, illuminate/support, isap-ou/laravel-enum-helpers, nesbot/carbon, php, spatie/laravel-data (+3 more)

### Community 25 - "Composer Scripts"
Cohesion: 0.18
Nodes (11): scripts, analyse, ci, deptrac, format, lint, test, @analyse (+3 more)

### Community 28 - "Package Keywords"
Cohesion: 0.20
Nodes (10): keywords, adyen, billing, cashier, contracts, laravel, payments, revolut (+2 more)

### Community 29 - "Checkout Session DTO"
Cohesion: 0.32
Nodes (4): expiresAt(), mode(), CarbonImmutable, CheckoutMode

### Community 30 - "Pending Price Change Tests"
Cohesion: 0.80
Nodes (3): Model, Subscription, SubscriptionPriceChangeScheduled

### Community 31 - "Dev Dependencies"
Cohesion: 0.29
Nodes (7): require-dev, deptrac/deptrac, larastan/larastan, laravel/pint, orchestra/testbench, phpstan/phpstan, phpunit/phpunit

### Community 33 - "Subscription Renewed Event"
Cohesion: 0.73
Nodes (4): Invoice, Model, Subscription, SubscriptionRenewed

### Community 34 - "Subscription Canceled Event"
Cohesion: 0.80
Nodes (3): Model, Subscription, SubscriptionCanceled

### Community 35 - "Subscription Created Event"
Cohesion: 0.80
Nodes (3): Model, Subscription, SubscriptionCreated

### Community 36 - "Subscription Past Due Event"
Cohesion: 0.80
Nodes (3): Model, Subscription, SubscriptionPastDue

### Community 38 - "Subscription Updated Event"
Cohesion: 0.80
Nodes (3): Model, Subscription, SubscriptionUpdated

### Community 39 - "CI Quality & Test Jobs"
Cohesion: 0.67
Nodes (4): Skill: Quality Check, quality job (PHPStan, Deptrac, Pint), CI Workflow: tests, tests job (Laravel 11/12/13 × PHP 8.2–8.5 matrix)

### Community 41 - "Laravel Package Auto-Discovery"
Cohesion: 0.50
Nodes (4): extra, laravel, providers, Isapp\\CashierSupport\\CashierSupportServiceProvider

### Community 43 - "PSR-4 Autoload"
Cohesion: 0.67
Nodes (3): autoload, psr-4, Isapp\\CashierSupport\\

### Community 44 - "Test Autoload"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Isapp\\CashierSupport\\Tests\\

### Community 45 - "Package Support Links"
Cohesion: 0.67
Nodes (3): support, issues, source

### Community 62 - "Manifest"
Cohesion: 0.18
Nodes (10): Added by us, absent from Cashier (deliberate — the multi-gateway abstraction), Billable methods (public API mirrored 1:1), Cashier Baseline — mirrored surface of laravel/cashier-stripe, Enums, Events (`src/Events`), Manifest, Reconciliation log, Subscription (model) methods (+2 more)

### Community 64 - "isapp/laravel-cashier-support"
Cohesion: 0.20
Nodes (9): Architecture, Capability system, How a provider connects, isapp/laravel-cashier-support, Known divergences from the reference (audited 2026-07-14), Navigating this package — use the graph, not grep, Purpose, Reference — laravel/cashier-stripe (+1 more)

### Community 65 - "Track Cashier — Upstream Drift Detector"
Cohesion: 0.22
Nodes (8): Automation — running this "constantly", Mapping table — upstream symbol → our file, Procedure, Report format, Rules, Sources of truth (in priority order — never trust training data), Track Cashier — Upstream Drift Detector, Why this exists

### Community 66 - "Capabilities — gate the intent, not the operation"
Cohesion: 0.33
Nodes (5): A capability gates what the CALLER meant, And the driver's escape hatch is not a back door, Capabilities — gate the intent, not the operation, Every setter is gated, or it goes silent, The gate lives here, not in the driver

### Community 68 - "AGENT.md"
Cohesion: 0.50
Nodes (3): Output format, Sources for this project, When invoked

## Knowledge Gaps
- **171 isolated node(s):** `name`, `description`, `type`, `license`, `laravel` (+166 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **72 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `TestCase` connect `Config, Enums & Labels` to `Service Provider Registration`, `Guarded Subscription Builder`, `Checkout Requests & Sessions`, `Billable Model Tests`, `Local Invoice Records & PDF`, `Webhook Handling & Events`, `Refunds`, `SubscriptionQuantityTest`?**
  _High betweenness centrality (0.093) - this node is a cross-community bridge._
- **Why does `FakeGateway` connect `Fake Gateway Test Double` to `Guarded Subscription Builder`, `Checkout Requests & Sessions`, `Billable Model Tests`, `Webhook Handling & Events`, `Config, Enums & Labels`, `Refunds`, `Fake Subscription Builder`, `SubscriptionQuantityTest`?**
  _High betweenness centrality (0.057) - this node is a cross-community bridge._
- **Why does `CashierException` connect `Customer & Invoice Contracts` to `Guarded Subscription Builder`, `Fake Gateway Test Double`, `Tax Rates & Invoice Concerns`, `Cashier Driver Manager`, `Webhook Handling & Events`, `Customer Record Persistence`?**
  _High betweenness centrality (0.042) - this node is a cross-community bridge._
- **Are the 40 inferred relationships involving `Cashier` (e.g. with `.quantity()` and `.trialDays()`) actually correct?**
  _`Cashier` has 40 INFERRED edges - model-reasoned connections that need verification._
- **Are the 12 inferred relationships involving `CheckoutRequest` (e.g. with `.test_a_malformed_argument_is_not_a_cashier_exception()` and `.test_a_non_positive_amount_never_reaches_a_driver()`) actually correct?**
  _`CheckoutRequest` has 12 INFERRED edges - model-reasoned connections that need verification._
- **What connects `name`, `description`, `type` to the rest of the system?**
  _171 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Customer & Invoice Contracts` be split into smaller, more focused modules?**
  _Cohesion score 0.05807622504537205 - nodes in this community are weakly interconnected._