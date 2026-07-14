# Sources of Truth (priority order)

1. **`vendor/laravel/cashier` (Stripe)** — the primary reference, and it is on disk: read it,
   do not remember it. Method names, argument order, which failures are typed and which are
   `InvalidArgumentException`, what an event means — match it unless there is a stated reason
   not to.
2. https://laravel.com/docs/12.x/billing — the Stripe Cashier docs, for intent and naming
   (https://github.com/laravel/cashier-stripe for anything newer than the vendored copy)
3. `vendor/laravel/cashier-paddle` — the second opinion. Where Stripe and Paddle agree,
   that is the shape of the abstraction; where they differ, the difference is usually the
   thing worth expressing as a capability.
4. `vendor/mollie/laravel-cashier-mollie` — last resort, and **not** a design authority: it
   builds its own local subscription engine (cycles, scheduled order items), which the
   smart-stub rule forbids. Consult it only for what Stripe and Paddle cannot answer, and
   say explicitly that you did.