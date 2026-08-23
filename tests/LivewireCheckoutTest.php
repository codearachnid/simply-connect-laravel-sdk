<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel\Tests;

use Livewire\Livewire;
use SimplyConnect\Laravel\Facades\SimplyConnect;
use SimplyConnect\Laravel\Livewire\Checkout;

final class LivewireCheckoutTest extends TestCase
{
    public function test_mount_opens_order_and_renders_checkout_config(): void
    {
        SimplyConnect::fake();

        $component = Livewire::test(Checkout::class, ['amount' => '49.90', 'currency' => 'EUR', 'clientUniqueId' => 'order-1', 'userTokenId' => 'user-7', 'order' => ['billingAddress' => ['country' => 'DE']], 'options' => ['country' => 'DE']]);

        $component->assertSet('sessionToken', 'fake-session-token')->assertSet('orderId', '123456789')->assertSet('status', null)
            ->assertSee('window.SimplyConnect.mount', false)->assertSee('fake-session-token', false);
        SimplyConnect::assertSent('openOrder', fn (array $r) => $r['amount'] === '49.90' && $r['currency'] === 'EUR' && $r['clientUniqueId'] === 'order-1' && $r['userTokenId'] === 'user-7' && $r['billingAddress'] === ['country' => 'DE']);

        $config = $component->instance()->checkoutConfig();
        $this->assertSame('fake-session-token', $config['sessionToken']);
        $this->assertSame('DE', $config['country']);
        $this->assertSame('49.90', $config['amount']);
        $this->assertSame('user-7', $config['userTokenId']);
    }

    public function test_client_unique_id_is_generated_when_omitted(): void
    {
        SimplyConnect::fake();
        $component = Livewire::test(Checkout::class, ['amount' => '1.00']);

        $this->assertNotEmpty($component->get('clientUniqueId'));
        $this->assertArrayNotHasKey('userTokenId', $component->instance()->checkoutConfig());
    }

    public function test_approved_result_is_verified_server_side(): void
    {
        SimplyConnect::fake(['getPaymentStatus' => ['transactionId' => '777', 'paymentOption' => ['userPaymentOptionId' => 'upo-1', 'card' => ['last4Digits' => '4242', 'cardBrand' => 'visa', 'uniqueCC' => 'secret']]]]);

        Livewire::test(Checkout::class, ['amount' => '10.00', 'clientUniqueId' => 'order-1'])
            ->call('handleResult', ['result' => 'APPROVED', 'transactionId' => 'browser-says-so'])
            ->assertSet('status', 'approved')
            ->assertSet('transaction.transactionId', '777')
            ->assertSet('transaction.userPaymentOptionId', 'upo-1')
            ->assertSet('transaction.card', ['last4Digits' => '4242', 'cardBrand' => 'visa'])
            ->assertDispatched('simply-connect:approved', status: 'approved', clientUniqueId: 'order-1')
            ->assertSee('approved');

        SimplyConnect::assertSent('getPaymentStatus', fn (array $r) => $r['sessionToken'] === 'fake-session-token');
    }

    public function test_declined_then_retry_opens_a_new_session(): void
    {
        SimplyConnect::fake(['getPaymentStatus' => ['transactionStatus' => 'DECLINED', 'gwErrorReason' => 'Insufficient funds']]);

        $component = Livewire::test(Checkout::class, ['amount' => '10.00'])
            ->call('handleResult', ['result' => 'DECLINED'])
            ->assertSet('status', 'declined')->assertSet('error', 'Insufficient funds')
            ->assertDispatched('simply-connect:declined')
            ->assertSee('Try again');

        $component->call('retry')->assertSet('status', null)->assertSet('error', null)->assertSet('transaction', null);
        SimplyConnect::assertSent('openOrder', fn () => true);
        $this->assertCount(2, \Illuminate\Support\Facades\Http::recorded(fn ($r) => str_ends_with($r->url(), 'openOrder.do')));
    }

    public function test_cancelled_and_expired_do_not_hit_the_api(): void
    {
        SimplyConnect::fake();

        Livewire::test(Checkout::class, ['amount' => '10.00'])->call('handleResult', ['cancelled' => true])
            ->assertSet('status', 'cancelled')->assertDispatched('simply-connect:cancelled');
        Livewire::test(Checkout::class, ['amount' => '10.00'])->call('handleResult', ['session_expired' => true])
            ->assertSet('status', 'error')->assertDispatched('simply-connect:error');

        SimplyConnect::assertNotSent('getPaymentStatus');
    }

    public function test_result_is_only_processed_once(): void
    {
        SimplyConnect::fake();

        Livewire::test(Checkout::class, ['amount' => '10.00'])
            ->call('handleResult', [])->call('handleResult', [])
            ->assertSet('status', 'approved');

        $this->assertCount(1, \Illuminate\Support\Facades\Http::recorded(fn ($r) => str_ends_with($r->url(), 'getPaymentStatus.do')));
    }

    public function test_api_failure_on_mount_shows_error(): void
    {
        SimplyConnect::fake(['openOrder' => ['status' => 'ERROR', 'errCode' => 1001, 'reason' => 'Invalid checksum']]);

        Livewire::test(Checkout::class, ['amount' => '10.00'])
            ->assertSet('sessionToken', null)->assertSet('status', 'error')->assertSee('temporarily unavailable');
    }

    public function test_amount_cannot_be_tampered_from_the_browser(): void
    {
        SimplyConnect::fake();

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(Checkout::class, ['amount' => '10.00'])->set('amount', '0.01');
    }
}
