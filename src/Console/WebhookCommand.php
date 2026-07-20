<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Console;

use Illuminate\Console\Command;
use Isapp\CashierSupport\CashierManager;
use Isapp\CashierSupport\Exceptions\CashierException;
use Isapp\CashierSupport\Exceptions\InvalidConfigurationException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Registers this application's webhook endpoint with a gateway.
 *
 * Everything here is generic: resolving the driver, checking it can do this at all,
 * defaulting the URL, and reporting. The gateway call is the driver's, behind
 * Contracts\RegistersWebhooks — and so is validating the event names, because they are
 * native ones and a copy of that catalogue here would be a second source of truth.
 *
 * The URL comes from the named route and never from config. That is not a detail: the
 * driver's own command used to build it from its own config key, so the key and the
 * route could drift apart — and the symptom of that drift is a webhook registered
 * against a URL that 404s on every delivery, with no error anywhere and subscriptions
 * that simply stop updating. Stripe already does it this way (route('cashier.webhook')).
 */
#[AsCommand(name: 'cashier:webhook')]
class WebhookCommand extends Command
{
    protected $signature = 'cashier:webhook
            {provider? : The gateway driver to register with; defaults to the configured one}
            {--url= : The publicly reachable webhook URL; defaults to this app\'s cashier.webhook route}
            {--events=* : Native event names to subscribe to; defaults to the gateway\'s whole catalogue}';

    protected $description = 'Register this application\'s webhook endpoint with a gateway.';

    /**
     * Injected rather than reached through the Cashier facade. A facade proxies through
     * method-tags in a docblock, and those cannot carry throws information — so every
     * catch below would read as dead to static analysis, while being exactly the paths
     * this command exists to report on.
     */
    public function __construct(private readonly CashierManager $cashier)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $provider = $this->resolveProvider();

        if ($provider === null) {
            return self::FAILURE;
        }

        try {
            $registrar = $this->cashier->webhookRegistrar($provider);
        } catch (InvalidConfigurationException $exception) {
            $this->error($exception->getMessage());
            $this->line('Known drivers: '.$this->knownDrivers());

            return self::FAILURE;
        }

        if ($registrar === null) {
            // No stub, no pretending: this gateway has no API for creating an endpoint,
            // and saying "done" would leave the operator believing a webhook exists.
            $this->error("The [{$provider}] driver cannot register webhooks over the API.");
            $this->line('Create the endpoint in the gateway\'s dashboard and point it at: '.$this->url($provider));

            return self::FAILURE;
        }

        $url = $this->url($provider);
        $events = $this->events();

        try {
            $registration = $registrar->registerWebhook($url, $events);
        } catch (CashierException $exception) {
            $this->error("Webhook registration failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Webhook registered with [{$provider}]: {$url}");
        $this->line("Endpoint id: {$registration->id}");

        // Contracts\RegistersWebhooks creates and does not read (#77), so neither this command
        // nor the driver can tell a first registration from a fifth. The gateway will deliver to
        // every endpoint it holds, and the duplicates are invisible from here — so say it rather
        // than let it be discovered by events arriving two and three times.
        $this->line('If an endpoint for this URL already existed, this created a second one — remove the extras in the gateway\'s dashboard.');

        if ($registration->secret === null) {
            // Stripe's case, and its own command says the same thing: the gateway does
            // not hand the secret back here. Not a failure.
            $this->line('This gateway does not return a signing secret here — retrieve it from its dashboard and set it in your environment.');

            return self::SUCCESS;
        }

        $this->warn('The signing secret is shown once and will appear in console/CI logs:');
        $this->line("Signing secret: {$registration->secret}");

        return self::SUCCESS;
    }

    /**
     * The driver to register with — the argument, or the configured default.
     */
    private function resolveProvider(): ?string
    {
        $argument = $this->argument('provider');

        if (is_string($argument) && $argument !== '') {
            return $argument;
        }

        try {
            return $this->cashier->getDefaultDriver();
        } catch (InvalidConfigurationException $exception) {
            $this->error($exception->getMessage());
            $this->line('Pass a driver explicitly, or set CASHIER_DRIVER. Known drivers: '.$this->knownDrivers());

            return null;
        }
    }

    /**
     * The requested events, or none — which the driver reads as "the gateway's whole
     * catalogue", not "the ones I apply" (#76).
     *
     * Not validated here: the catalogue is native, gateway-specific names, and a copy of
     * it in this package would be a second source of truth that goes stale. The driver
     * refuses a name the gateway does not document, per RegistersWebhooks::registerWebhook().
     *
     * @return array<int, string>
     */
    private function events(): array
    {
        $requested = $this->option('events');

        return is_array($requested) ? array_values(array_map('strval', $requested)) : [];
    }

    /**
     * The URL to register — the option, or this app's actual webhook route.
     */
    private function url(string $provider): string
    {
        $option = $this->option('url');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        return route('cashier.webhook', ['provider' => $provider]);
    }

    private function knownDrivers(): string
    {
        $drivers = $this->cashier->registeredDrivers();

        return $drivers === [] ? '(none registered)' : implode(', ', $drivers);
    }
}
