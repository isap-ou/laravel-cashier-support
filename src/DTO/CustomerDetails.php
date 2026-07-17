<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\DTO;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * What a billable entity is called at the gateway.
 *
 * Two fields, and the count is the design. `Concerns\ManagesCustomer` used to hand the gateway
 * an untyped `array<string, mixed>`, so the only thing a driver could do with a name was guess
 * which attribute of the app's model held it — `.claude/rules/capabilities.md` calls that back
 * door by name. This is the typed concept that replaces the bag: support resolves it, a driver
 * translates it into whatever its gateway calls those fields, and support never learns what a
 * wire format is.
 *
 * **Why only name and email.** They are what the two design authorities agree on
 * (`vendor/laravel/cashier` `ManagesCustomer.php:195,205`, `vendor/laravel/cashier-paddle`
 * `ManagesCustomer.php:95,105`), and agreement is what `CLAUDE.md` says the shape of an
 * abstraction is made of. Stripe's other four — phone, address, preferred_locales, metadata —
 * exist in one reference only, and `preferred_locales` is not even a concept: it is a field name
 * in Stripe's request body. Typing them here would carve one gateway's schema into the package
 * that must not know any gateway exists.
 *
 * Anything else a particular gateway wants rides in `$options`, which is not a silent drop
 * precisely because it is declared as the escape hatch — the same shape `CheckoutRequest` uses.
 */
class CustomerDetails extends Data
{
    /**
     * @param  string|null  $name  Null means "not specified", never "set to empty" — see the class note.
     * @param  string|null  $email  Null means "not specified", never "set to empty".
     * @param  array<string, mixed>  $options  Provider-specific escape hatch, named as such.
     */
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public array $options = [],
    ) {}

    /**
     * Lift the two typed fields out of an app-supplied options bag; the rest stays a bag.
     *
     * This is the one place the untyped array is allowed to exist, and it is why a driver never
     * sees one.
     *
     * A `name` that is not a string is a **programmer error**, and it raises rather than being
     * quietly rerouted — `.claude/rules/exceptions.md` draws that line: a billing failure is a
     * fact about the world and must be catchable, a malformed argument is meant to be fixed.
     * The first draft instead left a rejected key in `$options`, which was worse than either
     * of the obvious wrong answers: the hook then filled the typed field, so a driver received
     * `name: 'Ada'` alongside `options: ['name' => 42]` and decided between them by array-merge
     * order. One field must not arrive twice.
     *
     * An absent key, and an explicitly null one, are both "not specified" — the class note says
     * that is what null means, so throwing on `['name' => null]` would contradict it. Only a
     * value that is present, non-null and not a string is the error.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException When name or email is present and not a string.
     */
    public static function fromOptions(array $options): self
    {
        foreach (['name', 'email'] as $key) {
            $value = $options[$key] ?? null;

            if ($value !== null && ! is_string($value)) {
                throw new InvalidArgumentException(
                    "A customer's [{$key}] must be a string, ".get_debug_type($value).' given.',
                );
            }
        }

        /** @var string|null $name */
        $name = $options['name'] ?? null;
        /** @var string|null $email */
        $email = $options['email'] ?? null;

        unset($options['name'], $options['email']);

        return new self(name: $name, email: $email, options: $options);
    }
}
