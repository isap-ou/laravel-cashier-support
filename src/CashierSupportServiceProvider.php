<?php

declare(strict_types=1);

namespace Isapp\CashierSupport;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Isapp\CashierSupport\Console\WebhookCommand;

class CashierSupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cashier-support.php', 'cashier-support');

        $this->app->singleton(CashierManager::class, static fn (Container $app): CashierManager => new CashierManager($app));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'cashier-support');

        if ($this->app->runningInConsole()) {
            $this->commands([
                WebhookCommand::class,
            ]);

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'cashier-support-migrations');

            $this->publishes([
                __DIR__.'/../config/cashier-support.php' => config_path('cashier-support.php'),
            ], 'cashier-support-config');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/cashier-support'),
            ], 'cashier-support-lang');
        }
    }
}
