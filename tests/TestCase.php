<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel\Tests;

use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SimplyConnect\Laravel\Facades\SimplyConnect;
use SimplyConnect\Laravel\SimplyConnectLaravelServiceProvider;
use SimplyConnect\Laravel\SimplyConnectServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        // The SDK's own provider is auto-discovered after ours in real apps; mirror that here.
        return [LivewireServiceProvider::class, SimplyConnectLaravelServiceProvider::class, SimplyConnectServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['SimplyConnect' => SimplyConnect::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('simply-connect.merchant_id', '1234567890');
        $app['config']->set('simply-connect.merchant_site_id', '987654');
        $app['config']->set('simply-connect.secret_key', 'test-secret');
        $app['config']->set('simply-connect.environment', 'sandbox');
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }
}
