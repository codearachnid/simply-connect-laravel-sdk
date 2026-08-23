# Simply Connect for Laravel

Drop-in [Nuvei Simply Connect](https://docs.nuvei.com/documentation/accept-payment/simply-connect/) payments for Laravel: a Livewire checkout component, a Blade component, a tiny JS helper for Vue/React/Inertia, verified DMN webhooks, a facade and first-class test fakes. Built on the framework-agnostic [`codearachnid/simply-connect-php-sdk`](https://github.com/codearachnid/simply-connect-php-sdk), which handles checksums, environments and the Nuvei REST API.

| You want | You use |
|----------|---------|
| A checkout that just works | `<livewire:simply-connect.checkout amount="49.90" currency="USD" />` |
| A checkout in a plain Blade page | `<x-simply-connect::checkout :order="$order" />` + one JS event listener |
| A checkout in Vue / React / Inertia | `SimplyConnect::checkout($order)` as a prop + `SimplyConnect.mount(el, config)` |
| Webhooks verified for you | listen to `SimplyConnect\Laravel\Events\WebhookReceived` |
| Settle / refund / void, saved cards | `SimplyConnect::settle([...])`, `::refund([...])`, `::void([...])`, `::getUserPaymentOptions()` |
| Tests without hitting Nuvei | `SimplyConnect::fake()`, `SimplyConnect::assertSent()`, `SimplyConnect::fakeWebhook()` |

Requires PHP 8.2+, Laravel 11/12/13. Livewire 3 or 4 is optional (only for the Livewire component).

## Install

```bash
composer require codearachnid/simply-connect-laravel-sdk
```

> Until `codearachnid/simply-connect-php-sdk` is on Packagist, add it as a VCS repository to your app's `composer.json` first:
> ```json
> "repositories": [{ "type": "vcs", "url": "https://github.com/codearachnid/simply-connect-php-sdk" }]
> ```

Add your Nuvei credentials to `.env`:

```dotenv
SIMPLY_CONNECT_MERCHANT_ID=
SIMPLY_CONNECT_MERCHANT_SITE_ID=
SIMPLY_CONNECT_SECRET_KEY=
SIMPLY_CONNECT_ENV=sandbox        # production when live
# SIMPLY_CONNECT_WEBHOOK_URL=https://xxxx.ngrok.app/simply-connect/webhook  # local dev only
```

Optionally publish the config (defaults for every order / checkout, webhook path & middleware):

```bash
php artisan vendor:publish --tag=simply-connect-config
```

That's it — the service provider, facade, webhook route and components are auto-discovered.

## How a payment flows

1. **Server** opens a session: `/openOrder` → `sessionToken` (amount and currency are fixed here, the browser can't change them).
2. **Browser** renders Nuvei's form with `checkout({ sessionToken, ... })`, handles cards, APMs and 3DS, then fires `onResult`.
3. **Server** verifies: `/getPaymentStatus` (once, while the session is open) **and/or** the DMN webhook Nuvei sends to your site. Never trust the browser's result alone.

The package does 1 and 3 for you; pick how you want to do 2.

## Option A — Livewire

```blade
<livewire:simply-connect.checkout
    amount="49.90"
    currency="USD"
    client-unique-id="order-{{ $order->id }}"
    :user-token-id="auth()->id()"                         {{-- enables saved cards --}}
    :order="['billingAddress' => ['email' => $user->email, 'country' => 'US'], 'transactionType' => 'Sale']"
    :options="['country' => 'US', 'locale' => 'en_US', 'savePM' => true]" />
```

The component opens the order on mount, mounts Nuvei's form, and when the customer pays calls `/getPaymentStatus` server-side. It then shows a result (approved / declined / pending / cancelled / error, with a *Try again* button that opens a fresh session) and dispatches a browser event you can react to from a parent component, Alpine or plain JS:

```php
// Parent Livewire component
#[On('simply-connect:approved')]
public function paid(array $transaction, string $clientUniqueId): void
{
    Order::where('uuid', $clientUniqueId)->first()->markPaid($transaction['transactionId']);
    $this->redirect(route('orders.thanks'));
}
```

Events: `simply-connect:approved`, `:declined`, `:pending`, `:cancelled`, `:error`. Payload: `status`, `transaction` (`transactionId`, `transactionType`, `transactionStatus`, `amount`, `currency`, `userPaymentOptionId`, `card` with brand/last4/expiry), `error`, `clientUniqueId`.

Amount, currency, ids and the session token are `#[Locked]` — the browser cannot tamper with them.

### Extending the component

Subclass it to build the order from your own cart, hook outcomes, or use your own view:

```php
use SimplyConnect\Laravel\Livewire\Checkout;
use SimplyConnect\Response\TransactionResponse;

class PayInvoice extends Checkout
{
    public Invoice $invoice;

    protected function orderParams(): array
    {
        return [
            'amount' => $this->invoice->total,
            'currency' => $this->invoice->currency,
            'clientUniqueId' => 'invoice-' . $this->invoice->id,
            'userTokenId' => (string) $this->invoice->customer_id,
            'billingAddress' => ['email' => $this->invoice->email, 'country' => $this->invoice->country],
        ];
    }

    protected function approved(TransactionResponse $tx): void
    {
        $this->invoice->markPaid($tx->transactionId(), $tx->userPaymentOptionId());
        $this->redirect(route('invoices.show', $this->invoice));
    }
}
```

Register it as usual (`Livewire::component('pay-invoice', PayInvoice::class)`) and pass `:invoice="$invoice"`. Override `render()` to use your own Blade view (keep the `wire:ignore` mount `<div>` from the default view), or publish the default views to restyle them:

```bash
php artisan vendor:publish --tag=simply-connect-views
```

## Option B — Blade (no Livewire)

```php
// routes/web.php
Route::get('/pay/{order}', function (Order $order) {
    $session = SimplyConnect::openOrder([
        'amount' => $order->total,
        'currency' => $order->currency,
        'clientUniqueId' => 'order-' . $order->id,
        'userTokenId' => (string) $order->user_id,
        'billingAddress' => ['email' => $order->email, 'country' => $order->country],
    ]);

    return view('pay', ['order' => $order, 'session' => $session]);
});

Route::post('/pay/{order}/verify', function (Order $order, Request $request) {
    $tx = SimplyConnect::getPaymentStatus($request->string('sessionToken'));

    if ($tx->isApproved() && $tx->clientUniqueId() === 'order-' . $order->id) {
        $order->markPaid($tx->transactionId());
    }

    return ['status' => $tx->status()?->value, 'reason' => $tx->failureReason()];
})->name('pay.verify');
```

```blade
{{-- resources/views/pay.blade.php --}}
<x-simply-connect::checkout :order="$session" :options="['country' => 'US']" class="max-w-md" />

<script>
document.getElementById('simply-connect-checkout').addEventListener('simply-connect:result', async (e) => {
    const verified = await fetch('{{ route('pay.verify', $order) }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({ sessionToken: @js($session->sessionToken()) }),
    }).then(r => r.json());

    if (verified.status === 'APPROVED') location.href = '{{ route('orders.thanks', $order) }}';
});
</script>
```

`<x-simply-connect::checkout>` renders a container `<div>`, inlines a ~2 KB helper (once per page) that loads `checkout.js` from Nuvei's CDN, and calls `checkout({...})` with the server-built config. Nuvei callbacks are re-emitted as bubbling DOM events on the container: `simply-connect:ready`, `simply-connect:result`, `simply-connect:payment-event`, `simply-connect:form-validated`, `simply-connect:select-payment-method`, `simply-connect:payment-form-change`, `simply-connect:suggested-amount`, `simply-connect:upo-deleted` (`e.detail` is the callback argument). Alpine works too: `<div x-on:simply-connect:result="...">`.

Pass `order` an `OpenOrderResponse` (amount/currency/userTokenId/clientUniqueId are pre-filled) or a raw `sessionToken` string.

## Option C — Vue / React / Inertia

Server side, hand the browser a config instead of HTML — `CheckoutConfig` is `JsonSerializable`:

```php
return Inertia::render('Pay', [
    'checkout' => SimplyConnect::checkout($session, ['country' => 'US']),  // or response()->json(...)
]);
```

Browser side, import the helper (publish it with `php artisan vendor:publish --tag=simply-connect-js` → `resources/js/vendor/simply-connect.js`, or import straight from `vendor/`). It loads `checkout.js` on demand and returns a promise:

```jsx
// React
import SimplyConnect from '@/vendor/simply-connect';

export default function Pay({ checkout }) {
    const el = useRef(null);
    useEffect(() => {
        SimplyConnect.mount(el.current, checkout, {
            onResult: (result) => router.post('/pay/verify', { sessionToken: checkout.sessionToken }),
        });
        return () => SimplyConnect.destroy();
    }, [checkout.sessionToken]);
    return <div ref={el} />;
}
```

```vue
<!-- Vue -->
<script setup>
import SimplyConnect from '@/vendor/simply-connect';
const props = defineProps({ checkout: Object });
const el = ref(null);
onMounted(() => SimplyConnect.mount(el.value, props.checkout, {
    onResult: (result) => router.post('/pay/verify', { sessionToken: props.checkout.sessionToken }),
}));
onUnmounted(() => SimplyConnect.destroy());
</script>
<template><div ref="el" /></template>
```

`mount(target, config, callbacks)` accepts any [Simply Connect callback](https://docs.nuvei.com/documentation/accept-payment/simply-connect/event-callbacks/) (`onResult`, `prePayment`, `onPaymentEvent`, ...) and still emits the DOM events listed above. Prefer no helper at all? Load `SimplyConnect::scriptUrl()` yourself and call `window.checkout({ ...config, renderTo: '#el', onResult })` — the config is exactly what Nuvei expects.

## Webhooks (DMN)

Nuvei POSTs a Direct Merchant Notification for every transaction (sale, settle, refund, void, chargeback, ...) and retries for 24 hours until it gets a `200`. The package registers `GET|POST /simply-connect/webhook` (route name `simply-connect.webhook`, outside the `web` middleware group so CSRF doesn't interfere), verifies the `advanceResponseChecksum`, rejects forgeries with `400`, and fires an event:

```php
use SimplyConnect\Laravel\Events\WebhookReceived;

class HandleNuveiWebhook
{
    public function handle(WebhookReceived $event): void
    {
        $dmn = $event->dmn;   // SimplyConnect\Webhook\Dmn — already authenticated

        if ($dmn->isApproved() && $dmn->transactionType() === 'Sale') {
            Order::where('uuid', $dmn->clientUniqueId())->first()?->markPaid($dmn->transactionId());
        }
        if ($dmn->transactionType() === 'Chargeback') { /* ... */ }
    }
}
```

`openOrder()` automatically sets `urlDetails.notificationUrl` to this route, so Nuvei knows where to send DMNs. Locally, expose your app (ngrok, Expose) and set `SIMPLY_CONNECT_WEBHOOK_URL` so the URL sent to Nuvei is reachable. Make listeners idempotent (key on `$dmn->transactionId()`) — a listener that throws makes Laravel answer 500 and Nuvei redeliver. Queue heavy work.

Disable the built-in route with `simply-connect.webhook.enabled = false` and call `SimplyConnect::webhook($request)` in your own controller if you prefer.

## After the payment

```php
SimplyConnect::settle(['relatedTransactionId' => $authTxId, 'amount' => '49.90', 'currency' => 'USD', 'clientUniqueId' => 'order-1001']);
SimplyConnect::refund(['relatedTransactionId' => $saleTxId, 'amount' => '10.00', 'currency' => 'USD', 'clientUniqueId' => 'order-1001-r1']);
SimplyConnect::void(['relatedTransactionId' => $authTxId, 'amount' => '49.90', 'currency' => 'USD', 'clientUniqueId' => 'order-1001-v']);

SimplyConnect::getUserPaymentOptions($userTokenId);                 // saved cards / APMs
SimplyConnect::deleteUserPaymentOption($userTokenId, $upoId);
SimplyConnect::getMerchantPaymentMethods($sessionToken);           // build pmWhitelist/pmBlacklist
SimplyConnect::updateOrder([...]);                                  // change an open session
SimplyConnect::call('getCardDetails', [...], Checksum::SESSION);    // any other REST 1.0 endpoint
SimplyConnect::client();                                            // the underlying SDK Client
```

All responses, exceptions and parameter names are the SDK's — see its [README](https://github.com/codearachnid/simply-connect-php-sdk#readme). Declines are normal responses (`$tx->isDeclined()`); only API-level failures throw `SimplyConnect\Exception\ApiException`.

## Configuration

`config/simply-connect.php`:

| Key | Purpose |
|-----|---------|
| `merchant_id`, `merchant_site_id`, `secret_key`, `environment`, `hash_algorithm` | Nuvei credentials; `sandbox` or `production`; `sha256` unless your site uses md5 |
| `http.timeout` | Seconds. Requests go through Laravel's HTTP client (`Http::fake()`, Telescope, retries all work) |
| `order` | Defaults merged into every `openOrder()` (e.g. `transactionType`, `urlDetails`) |
| `checkout` | Defaults merged into every `checkout({...})` config (`locale`, `country`, `savePM`, `pmBlacklist`, `showResponseMessage`, ...) |
| `webhook.enabled`, `.path`, `.middleware`, `.url` | DMN route; `url` overrides the notification URL sent to Nuvei (local dev) |

Bind your own `SimplyConnect\Http\Transport` in the container to replace the Laravel HTTP client transport.

## Testing your app

`SimplyConnect::fake()` stubs every Nuvei endpoint through `Http::fake()` with realistic approved responses; pass overrides per endpoint (arrays merge over the default, closures receive the `Illuminate\Http\Client\Request`):

```php
use SimplyConnect\Laravel\Facades\SimplyConnect;

SimplyConnect::fake();                                                     // everything approved
SimplyConnect::fake(['getPaymentStatus' => ['transactionStatus' => 'DECLINED', 'gwErrorReason' => 'Do not honor']]);
SimplyConnect::fake(['openOrder' => fn ($request) => ['status' => 'ERROR', 'errCode' => 1001, 'reason' => 'Invalid checksum']]);

$this->get('/pay/1')->assertOk();

SimplyConnect::assertSent('openOrder', fn (array $request) => $request['amount'] === '49.90' && $request['currency'] === 'USD');
SimplyConnect::assertNotSent('refundTransaction');

// Webhooks: a correctly signed DMN for your configured secret
$this->post(route('simply-connect.webhook'), SimplyConnect::fakeWebhook(['clientUniqueId' => 'order-1', 'totalAmount' => '49.90']))->assertOk();
$this->post(route('simply-connect.webhook'), SimplyConnect::fakeWebhook(['Status' => 'DECLINED']))->assertOk();

// Livewire
Livewire::test(Checkout::class, ['amount' => '49.90'])
    ->call('handleResult', ['result' => 'APPROVED'])
    ->assertSet('status', 'approved')
    ->assertDispatched('simply-connect:approved');
```

In Nuvei's sandbox use their [test cards](https://docs.nuvei.com/documentation/integration/testing/testing-cards/) (`4000020951595032` frictionless 3DS, `2221008123677736` challenge).

## Package development

```bash
composer install
composer check   # phpstan level 8 + phpunit
```

## License

MIT — see [LICENSE](LICENSE).
