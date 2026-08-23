<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel\Tests;

use Illuminate\Support\Facades\Event;
use SimplyConnect\Laravel\Events\WebhookReceived;
use SimplyConnect\Laravel\Facades\SimplyConnect;

final class WebhookTest extends TestCase
{
    public function test_valid_dmn_fires_event_and_returns_200(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->post(route('simply-connect.webhook'), SimplyConnect::fakeWebhook(['clientUniqueId' => 'order-42', 'TransactionID' => '555']))
            ->assertOk();

        Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e) => $e->dmn->isApproved() && $e->dmn->clientUniqueId() === 'order-42' && $e->dmn->transactionId() === '555');
    }

    public function test_dmn_can_arrive_as_get(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->get(route('simply-connect.webhook', SimplyConnect::fakeWebhook()))->assertOk();

        Event::assertDispatched(WebhookReceived::class);
    }

    public function test_tampered_dmn_is_rejected(): void
    {
        Event::fake([WebhookReceived::class]);
        $payload = SimplyConnect::fakeWebhook();
        $payload['totalAmount'] = '9999.00';

        $this->post(route('simply-connect.webhook'), $payload)->assertStatus(400);
        $this->post(route('simply-connect.webhook'), [])->assertStatus(400);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function test_route_honours_path_and_middleware_config(): void
    {
        $this->assertSame(url('/simply-connect/webhook'), route('simply-connect.webhook'));
        $this->assertSame([], app('router')->getRoutes()->getByName('simply-connect.webhook')->gatherMiddleware());
    }
}
