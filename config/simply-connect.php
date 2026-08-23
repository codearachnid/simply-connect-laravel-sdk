<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nuvei credentials
    |--------------------------------------------------------------------------
    | From the Nuvei Control Panel (Integration Settings). Use your sandbox
    | credentials with SIMPLY_CONNECT_ENV=sandbox and production ones with
    | SIMPLY_CONNECT_ENV=production. hash_algorithm is sha256 unless your
    | Nuvei site is explicitly configured for md5.
    */

    'merchant_id' => env('SIMPLY_CONNECT_MERCHANT_ID', ''),
    'merchant_site_id' => env('SIMPLY_CONNECT_MERCHANT_SITE_ID', ''),
    'secret_key' => env('SIMPLY_CONNECT_SECRET_KEY', ''),
    'environment' => env('SIMPLY_CONNECT_ENV', 'sandbox'),
    'hash_algorithm' => env('SIMPLY_CONNECT_HASH_ALGORITHM', 'sha256'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    | Requests go through Laravel's HTTP client (so Http::fake() and Telescope
    | work). Timeout in seconds.
    */

    'http' => [
        'timeout' => (int) env('SIMPLY_CONNECT_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults merged into every /openOrder request
    |--------------------------------------------------------------------------
    | Anything from the /openOrder reference, e.g. transactionType, or
    | urlDetails. Per-call parameters win.
    */

    'order' => [
        // 'transactionType' => 'Sale', // Sale | Auth | PreAuth
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults merged into every browser-side checkout({...}) config
    |--------------------------------------------------------------------------
    | See Payment Customization and UI Customization in the Nuvei docs.
    | Per-call options win. Callbacks are wired by the Blade/Livewire
    | components and should not be set here.
    */

    'checkout' => [
        'locale' => 'en_US',
        // 'country' => 'US',
        // 'showResponseMessage' => false,
        // 'savePM' => true,
        // 'pmBlacklist' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | DMN webhook
    |--------------------------------------------------------------------------
    | Nuvei POSTs (or GETs) a Direct Merchant Notification to your site for
    | every transaction. When enabled, the package registers the route, verifies
    | the advanceResponseChecksum, fires SimplyConnect\Laravel\Events\WebhookReceived
    | and answers 200. The route is registered outside the "web" group, so no
    | CSRF/session middleware applies; add your own via "middleware".
    |
    | openOrder() sets urlDetails.notificationUrl to this route automatically.
    | Set "url" to override it (e.g. an ngrok URL while developing locally).
    */

    'webhook' => [
        'enabled' => true,
        'path' => 'simply-connect/webhook',
        'middleware' => [],
        'url' => env('SIMPLY_CONNECT_WEBHOOK_URL'),
    ],

];
