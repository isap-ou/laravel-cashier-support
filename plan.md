# Plan — isapp/laravel-cashier-support

## Фаза 1: Enums + Exceptions

- [ ] `src/Enums/PaymentStatus.php` — pending, processing, succeeded, failed, canceled, refunded
- [ ] `src/Enums/SubscriptionStatus.php` — active, past_due, canceled, incomplete, trialing, paused
- [ ] `src/Enums/Currency.php` — ISO 4217: EUR, USD, GBP, PLN, CZK и др.
- [ ] `src/Enums/PaymentMethodType.php` — card, bank_transfer, revolut_pay, apple_pay, google_pay, sepa
- [ ] `src/Enums/RefundReason.php` — duplicate, fraudulent, requested_by_customer, other
- [ ] `src/Enums/WebhookEvent.php` — payment.succeeded, subscription.created, refund.completed...
- [ ] `src/Enums/Interval.php` — day, week, month, year
- [ ] `src/Enums/CheckoutMode.php` — payment, subscription, setup
- [ ] `src/Exceptions/CashierException.php`
- [ ] `src/Exceptions/PaymentFailedException.php`
- [ ] `src/Exceptions/IncompletePaymentException.php`
- [ ] `src/Exceptions/CustomerNotFoundException.php`
- [ ] `src/Exceptions/InvalidConfigurationException.php`
- [ ] `src/Exceptions/WebhookVerificationException.php`
- [ ] `src/Exceptions/SubscriptionUpdateFailure.php`

## Фаза 2: DTO

- [ ] `src/DTO/Customer.php`
- [ ] `src/DTO/Payment.php`
- [ ] `src/DTO/Subscription.php`
- [ ] `src/DTO/SubscriptionItem.php`
- [ ] `src/DTO/Invoice.php`
- [ ] `src/DTO/InvoiceLine.php`
- [ ] `src/DTO/PaymentMethod.php`
- [ ] `src/DTO/Refund.php`
- [ ] `src/DTO/CheckoutSession.php`
- [ ] `src/DTO/WebhookPayload.php`

## Фаза 3: Contracts (Interfaces)

- [ ] `src/Contracts/GatewayProvider.php` — центральный интерфейс, resolve-точка
- [ ] `src/Contracts/CustomerOperations.php`
- [ ] `src/Contracts/ChargeOperations.php`
- [ ] `src/Contracts/SubscriptionOperations.php`
- [ ] `src/Contracts/SubscriptionBuilder.php`
- [ ] `src/Contracts/InvoiceOperations.php`
- [ ] `src/Contracts/PaymentMethodOperations.php`
- [ ] `src/Contracts/CheckoutOperations.php`
- [ ] `src/Contracts/WebhookHandler.php`

## Фаза 4: Concerns (Traits) + Billable

- [ ] `src/Concerns/ManagesCustomer.php`
- [ ] `src/Concerns/ManagesSubscriptions.php`
- [ ] `src/Concerns/ManagesPaymentMethods.php`
- [ ] `src/Concerns/ManagesInvoices.php`
- [ ] `src/Concerns/PerformsCharges.php`
- [ ] `src/Concerns/HandlesCheckout.php`
- [ ] `src/Concerns/HandlesTaxes.php`
- [ ] `src/Billable.php` — мета-trait

## Фаза 5: Абстрактные модели + Events + ServiceProvider

- [ ] `src/Models/Subscription.php`
- [ ] `src/Models/SubscriptionItem.php`
- [ ] `src/Events/WebhookReceived.php`
- [ ] `src/Events/WebhookHandled.php`
- [ ] `src/Events/SubscriptionCreated.php`
- [ ] `src/Events/SubscriptionUpdated.php`
- [ ] `src/Events/SubscriptionCanceled.php`
- [ ] `src/Events/PaymentSucceeded.php`
- [ ] `src/Events/PaymentFailed.php`
- [ ] `src/Events/RefundProcessed.php`
- [ ] `src/Cashier.php` — статический конфиг
- [ ] `src/CashierSupportServiceProvider.php`

## Фаза 6: Тесты + CI

- [ ] Юнит-тесты на все DTO (fromArray/toArray)
- [ ] Юнит-тесты на все Enum (values, labels)
- [ ] PHPStan level 8 без ошибок
- [ ] Pint без замечаний
- [ ] README.md
