<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use SimplyConnect\Laravel\Facades\SimplyConnect;

final class BladeComponentTest extends TestCase
{
    public function test_checkout_component_renders_container_and_mount_call(): void
    {
        SimplyConnect::fake();
        $order = SimplyConnect::openOrder(['amount' => '10.00', 'currency' => 'USD', 'clientUniqueId' => 'o-1']);

        $html = Blade::render('<x-simply-connect::checkout :order="$order" :options="[\'country\' => \'US\']" class="w-full" />', ['order' => $order]);

        $this->assertSame(1, substr_count($html, 'root.SimplyConnect = api'), 'helper inlined exactly once');
        $this->assertStringContainsString('<div id="simply-connect-checkout" class="w-full"></div>', $html);
        $this->assertStringContainsString("window.SimplyConnect.mount('#simply-connect-checkout'", $html);
        $this->assertStringContainsString('fake-session-token', $html);
        $this->assertStringContainsString('\\u0022country\\u0022:\\u0022US\\u0022', $html);
    }

    public function test_checkout_component_accepts_a_raw_session_token(): void
    {
        $html = Blade::render('<x-simply-connect::checkout order="tok-123" id="pay" />');

        $this->assertStringContainsString('tok-123', $html);
        $this->assertStringContainsString('id="pay"', $html);
    }
}
