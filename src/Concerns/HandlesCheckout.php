<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Isapp\CashierSupport\Contracts\CheckoutSession;
use Isapp\CashierSupport\DTO\CheckoutRequest;
use Isapp\CashierSupport\Enums\CheckoutMode;

/**
 * Hosted checkout for a billable model.
 *
 * @phpstan-require-extends Model
 */
trait HandlesCheckout
{
    use InteractsWithProvider;

    /**
     * Create a hosted checkout session for the entity.
     *
     * The gate keys on the SHAPE of the request, so a gateway that takes an
     * amount is never handed a catalogue of price ids — and a mis-shaped request
     * throws UnsupportedOperationException here, before any driver sees it. That
     * is what keeps drivers from inventing their own exceptions for it.
     *
     * A bare price id or an items map is still accepted — it is the same
     * price-shaped request, spelled the way Stripe Cashier spells it. Note that
     * this means an amount-only gateway now REFUSES it: an amount used to be
     * smuggled through options and read by the driver, and that is exactly the
     * hole this closes. Ask for an amount with CheckoutRequest::forAmount().
     *
     * @param  array<string, int>|string|CheckoutRequest  $items
     * @param  array<string, mixed>  $options
     */
    public function checkout(array|string|CheckoutRequest $items, array $options = []): CheckoutSession
    {
        if ($items instanceof CheckoutRequest && $options !== []) {
            // The request already names every field the options bag used to
            // carry. Merging them would resurrect the bag; ignoring them would
            // silently drop a success_url the caller believes they passed.
            throw new InvalidArgumentException(
                'Pass options through CheckoutRequest; the second argument is only for the price-and-options form.',
            );
        }

        $request = $items instanceof CheckoutRequest
            ? $items
            : $this->checkoutRequestFromLegacyArguments($items, $options);

        return $this->cashierProvider()->checkout($this, $request);
    }

    /**
     * Turn the Stripe-style ($items, $options) arguments into a request.
     *
     * The urls and the mode used to travel in the options bag, and each driver
     * fished them out under whatever key it happened to read. They are typed
     * fields now, so they are lifted out here — otherwise such a call would
     * hand the driver a request whose successUrl is null while the url sits
     * unread in options.
     *
     *
     * @param  array<string, int>|string  $items
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException When options['mode'] names no known mode.
     */
    private function checkoutRequestFromLegacyArguments(array|string $items, array $options): CheckoutRequest
    {
        $successUrl = $options['success_url'] ?? null;
        $cancelUrl = $options['cancel_url'] ?? null;
        $mode = $options['mode'] ?? null;

        // Not a silent fallback to Payment for anything unrecognised: quietly
        // checking out in the wrong mode is how a subscription becomes a one-off
        // charge, and the caller never hears about it.
        $mode = match (true) {
            $mode === null => CheckoutMode::Payment,
            $mode instanceof CheckoutMode => $mode,
            is_string($mode) => CheckoutMode::tryFrom($mode)
                ?? throw new InvalidArgumentException("Unknown checkout mode [{$mode}]."),
            default => throw new InvalidArgumentException(
                'The checkout mode must be a CheckoutMode or its string value; got ['.get_debug_type($mode).'].',
            ),
        };

        return CheckoutRequest::forPrices(
            items: $items,
            successUrl: is_string($successUrl) ? $successUrl : null,
            cancelUrl: is_string($cancelUrl) ? $cancelUrl : null,
            mode: $mode,
            options: Arr::except($options, ['success_url', 'cancel_url', 'mode']),
        );
    }
}
