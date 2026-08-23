<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use SimplyConnect\Client;
use SimplyConnect\Environment;
use SimplyConnect\Http\Transport;
use SimplyConnect\Laravel\Http\Controllers\WebhookController;
use SimplyConnect\Laravel\Http\LaravelTransport;
use SimplyConnect\Laravel\Livewire\Checkout;

final class SimplyConnectLaravelServiceProvider extends ServiceProvider
{
    public const JS_PATH = __DIR__ . '/../resources/js/simply-connect.js';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/simply-connect.php', 'simply-connect');

        // Bind your own Transport to replace the Laravel HTTP client transport.
        $this->app->bindIf(Transport::class, fn (Application $app) => new LaravelTransport(
            $app->make(HttpFactory::class),
            (int) config('simply-connect.http.timeout', 30),
        ));

        $this->bindClient();
        // The SDK ships its own auto-discovered provider that also binds Client (with a cURL
        // transport) and registers after this one, so re-assert our binding just before boot.
        $this->app->booting(fn () => $this->bindClient());

        $this->app->singleton(SimplyConnect::class, fn (Application $app) => new SimplyConnect($app->make(Client::class), $app->make(Repository::class)));
    }

    private function bindClient(): void
    {
        $this->app->singleton(Client::class, function (Application $app): Client {
            $config = (array) config('simply-connect');

            return new Client(
                merchantId: (string) ($config['merchant_id'] ?? ''),
                merchantSiteId: (string) ($config['merchant_site_id'] ?? ''),
                merchantSecretKey: (string) ($config['secret_key'] ?? ''),
                environment: Environment::fromName((string) ($config['environment'] ?? 'sandbox')),
                transport: $app->make(Transport::class),
                hashAlgorithm: (string) ($config['hash_algorithm'] ?? 'sha256'),
            );
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'simply-connect');

        if (config('simply-connect.webhook.enabled')) {
            Route::match(['GET', 'POST'], (string) config('simply-connect.webhook.path'), WebhookController::class)
                ->middleware((array) config('simply-connect.webhook.middleware', []))
                ->name('simply-connect.webhook');
        }

        if (class_exists(Livewire::class)) {
            Livewire::component('simply-connect.checkout', Checkout::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/simply-connect.php' => $this->app->configPath('simply-connect.php')], 'simply-connect-config');
            $this->publishes([__DIR__ . '/../resources/views' => $this->app->resourcePath('views/vendor/simply-connect')], 'simply-connect-views');
            $this->publishes([self::JS_PATH => $this->app->resourcePath('js/vendor/simply-connect.js')], 'simply-connect-js');
        }
    }
}
