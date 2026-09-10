<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use SimplyConnect\Client;
use SimplyConnect\Environment;
use SimplyConnect\Exception\ApiException;
use SimplyConnect\Laravel\Facades\SimplyConnect;

final class SimplyConnectTest extends TestCase
{
    public function test_client_is_bound_from_config(): void
    {
        $client = $this->app->make(Client::class);

        $this->assertSame($client, (fn () => $this->client)->call($this->app->make(\SimplyConnect\Laravel\SimplyConnect::class)));
        $transport = (fn () => $this->transport)->call($client);
        $this->assertInstanceOf(\SimplyConnect\Laravel\Http\LaravelTransport::class, (new \ReflectionFunction($transport))->getClosureThis(), 'requests route through the Laravel HTTP client');
    }

    public function test_open_order_merges_defaults_and_sets_notification_url(): void
    {
        config()->set('simply-connect.order', ['transactionType' => 'Auth', 'urlDetails' => ['successUrl' => 'https://shop.test/ok']]);
        SimplyConnect::fake();

        $order = SimplyConnect::openOrder(['amount' => '10.00', 'currency' => 'USD', 'clientUniqueId' => 'o-1']);

        $this->assertSame('fake-session-token', $order->sessionToken());
        $this->assertSame('10.00', $order->amount());
        SimplyConnect::assertSent('openOrder', function (array $request) {
            $this->assertSame('Auth', $request['transactionType']);
            $this->assertSame('https://shop.test/ok', $request['urlDetails']['successUrl']);
            $this->assertSame(route('simply-connect.webhook'), $request['urlDetails']['notificationUrl']);
            $this->assertSame('1234567890', $request['merchantId']);
            $this->assertSame(64, strlen($request['checksum']));

            return true;
        });
    }

    public function test_explicit_notification_url_and_override_url_win(): void
    {
        SimplyConnect::fake();
        SimplyConnect::openOrder(['amount' => '1', 'currency' => 'USD', 'clientUniqueId' => 'o', 'urlDetails' => ['notificationUrl' => 'https://mine.test/dmn']]);
        SimplyConnect::assertSent('openOrder', fn (array $r) => $r['urlDetails']['notificationUrl'] === 'https://mine.test/dmn');

        config()->set('simply-connect.webhook.url', 'https://abc.ngrok.app/simply-connect/webhook');
        $this->assertSame('https://abc.ngrok.app/simply-connect/webhook', SimplyConnect::webhookUrl());

        config()->set('simply-connect.webhook.enabled', false);
        $this->assertNull(SimplyConnect::webhookUrl());
    }

    public function test_checkout_config_applies_defaults_under_options(): void
    {
        config()->set('simply-connect.checkout', ['locale' => 'en_US', 'country' => 'US']);
        SimplyConnect::fake();
        $order = SimplyConnect::openOrder(['amount' => '10.00', 'currency' => 'EUR', 'clientUniqueId' => 'o-1', 'userTokenId' => 'u-1']);

        $this->assertSame(Environment::Sandbox, SimplyConnect::environment());
        $config = SimplyConnect::checkout($order, ['country' => 'DE'])->jsonSerialize();

        $this->assertSame([
            'sessionToken' => 'fake-session-token', 'env' => 'int', 'merchantId' => '1234567890', 'merchantSiteId' => '987654',
            'amount' => '10.00', 'currency' => 'EUR', 'userTokenId' => 'u-1', 'clientUniqueId' => 'o-1', 'locale' => 'en_US', 'country' => 'DE',
        ], $config);
    }

    public function test_fake_overrides_and_closures(): void
    {
        SimplyConnect::fake([
            'getPaymentStatus' => ['transactionStatus' => 'DECLINED', 'gwErrorReason' => 'Do not honor'],
            'refundTransaction' => fn () => ['status' => 'ERROR', 'errCode' => 1, 'reason' => 'Nope'],
        ]);

        $tx = SimplyConnect::getPaymentStatus('tok');
        $this->assertTrue($tx->isDeclined());
        $this->assertSame('Do not honor', $tx->failureReason());

        $settle = SimplyConnect::settle(['relatedTransactionId' => '1', 'amount' => '1', 'currency' => 'USD', 'clientUniqueId' => 'o']);
        $this->assertTrue($settle->isApproved());
        $this->assertSame('Settle', $settle->transactionType());

        $this->expectException(ApiException::class);
        SimplyConnect::refund(['relatedTransactionId' => '1', 'amount' => '1', 'currency' => 'USD', 'clientUniqueId' => 'o']);
    }

    public function test_requests_go_through_laravel_http_client(): void
    {
        Http::fake(['ppp-test.nuvei.com/*' => Http::response(['status' => 'SUCCESS', 'sessionToken' => 'via-http-fake'])]);

        $this->assertSame('via-http-fake', SimplyConnect::openOrder(['amount' => '1', 'currency' => 'USD', 'clientUniqueId' => 'o'])->sessionToken());
        Http::assertSent(fn ($r) => $r->url() === 'https://ppp-test.nuvei.com/ppp/api/v1/openOrder.do' && $r->isJson());
    }

    public function test_fake_webhook_is_signed(): void
    {
        $dmn = SimplyConnect::webhook(SimplyConnect::fakeWebhook(['clientUniqueId' => 'o-9', 'totalAmount' => '5.00']));

        $this->assertTrue($dmn->isApproved());
        $this->assertSame('o-9', $dmn->clientUniqueId());
        $this->assertSame('5.00', $dmn->totalAmount());
    }
}
