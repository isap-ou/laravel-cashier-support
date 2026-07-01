<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests;

use Illuminate\Foundation\Application;
use Isapp\CashierSupport\CashierSupportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelPdf\PdfServiceProvider;

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
            PdfServiceProvider::class,
            CashierSupportServiceProvider::class,
        ];
    }
}
