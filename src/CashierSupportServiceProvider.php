<?php

declare(strict_types=1);

namespace Isapp\CashierSupport;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

class CashierSupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cashier-support.php', 'cashier-support');

        $this->app->singleton(CashierManager::class, static fn (Container $app): CashierManager => new CashierManager($app));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'cashier-support');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cashier-support');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cashier-support.php' => config_path('cashier-support.php'),
            ], 'cashier-support-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/cashier-support'),
            ], 'cashier-support-views');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/cashier-support'),
            ], 'cashier-support-lang');
        }
    }
}
