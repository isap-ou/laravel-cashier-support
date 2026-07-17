<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests;

use Illuminate\Foundation\Application;
use Isapp\CashierSupport\CashierSupportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            CashierSupportServiceProvider::class,
        ];
    }
}
