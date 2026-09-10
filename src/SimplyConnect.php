<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use SimplyConnect\Checkout\CheckoutConfig;
use SimplyConnect\Checksum;
use SimplyConnect\Client;
use SimplyConnect\Environment;
use SimplyConnect\Response\OpenOrderResponse;
use SimplyConnect\Webhook\Dmn;

/**
 * Laravel-flavoured entry point over the framework-agnostic SDK Client.
 * Adds config defaults, the webhook URL, and test fakes; every other
 * Client method (getPaymentStatus, settle, refund, void, ...) is proxied.
 *
 * @method \SimplyConnect\Response\TransactionResponse getPaymentStatus(string $sessionToken)
 * @method \SimplyConnect\Response\OpenOrderResponse updateOrder(array<string, mixed> $params)
 * @method \SimplyConnect\Response\TransactionResponse settle(array<string, mixed> $params)
 * @method \SimplyConnect\Response\TransactionResponse refund(array<string, mixed> $params)
 * @method \SimplyConnect\Response\TransactionResponse void(array<string, mixed> $params)
 * @method \SimplyConnect\Response\ApiResponse getMerchantPaymentMethods(string $sessionToken, array<string, mixed> $params = [])
 * @method \SimplyConnect\Response\ApiResponse getUserPaymentOptions(string $userTokenId)
 * @method \SimplyConnect\Response\ApiResponse deleteUserPaymentOption(string $userTokenId, string $userPaymentOptionId)
 * @method \SimplyConnect\Response\ApiResponse call(string $endpoint, array<string, mixed> $params, ?list<string> $checksumOrder = Checksum::ORDER)
 */
class SimplyConnect
{
    public function __construct(
        private readonly Client $client,
        private readonly Repository $config,
    ) {
    }

    public function environment(): Environment
    {
        return Environment::fromName((string) $this->config->get('simply-connect.environment', 'sandbox'));
    }

    /**
     * Open a payment session. Merges config('simply-connect.order') and sets
     * urlDetails.notificationUrl to the package webhook route when enabled.
     *
     * @param array<string, mixed> $params
     */
    public function openOrder(array $params): OpenOrderResponse
    {
        $params = array_replace_recursive((array) $this->config->get('simply-connect.order', []), $params);

        if (!isset($params['urlDetails']['notificationUrl']) && ($url = $this->webhookUrl()) !== null) {
            $params['urlDetails'] = ($params['urlDetails'] ?? []) + ['notificationUrl' => $url];
        }

        return $this->client->openOrder($params);
    }

    /**
     * Browser-side checkout({...}) config, with config('simply-connect.checkout')
     * defaults applied underneath $options.
     *
     * @param array<string, mixed> $options
     */
    public function checkout(OpenOrderResponse|string $order, array $options = []): CheckoutConfig
    {
        return $this->client->checkout($order, array_replace((array) $this->config->get('simply-connect.checkout', []), $options));
    }

    /**
     * Authenticate an incoming DMN. Throws InvalidChecksumException if forged.
     *
     * @param HttpRequest|array<string, mixed>|null $input current request when null
     */
    public function webhook(HttpRequest|array|null $input = null): Dmn
    {
        $input ??= request();

        return $this->client->webhook($input instanceof HttpRequest ? $input->all() : $input);
    }

    /** Absolute URL Nuvei should send DMNs to, or null when the webhook is disabled. */
    public function webhookUrl(): ?string
    {
        if (!$this->config->get('simply-connect.webhook.enabled')) {
            return null;
        }
        $url = $this->config->get('simply-connect.webhook.url');

        return is_string($url) && $url !== '' ? $url : route('simply-connect.webhook');
    }

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    */

    /**
     * Stub every Nuvei endpoint via Http::fake(). Pass endpoint => array (merged
     * over a sensible approved default) or endpoint => Closure(Request): array.
     *
     *   SimplyConnect::fake(['getPaymentStatus' => ['transactionStatus' => 'DECLINED']]);
     *
     * @param array<string, array<string, mixed>|\Closure> $responses
     */
    public function fake(array $responses = []): static
    {
        $base = $this->environment()->apiBaseUrl();
        $stubs = [];

        foreach (array_keys($responses + $this->fakeDefaults()) as $endpoint) {
            $override = $responses[$endpoint] ?? null;
            $stubs[$base . $endpoint . '.do'] = function (Request $request) use ($endpoint, $override) {
                $default = $this->fakeDefaults($request)[$endpoint] ?? ['status' => 'SUCCESS'];
                $body = $override instanceof \Closure ? $override($request) : array_replace($default, $override ?? []);

                return Http::response($body);
            };
        }
        $stubs[$base . '*'] = Http::response(['status' => 'SUCCESS']);

        Http::fake($stubs);

        return $this;
    }

    /** Assert an endpoint was called; $callback receives the decoded request array. */
    public function assertSent(string $endpoint, ?callable $callback = null): static
    {
        Http::assertSent(fn (Request $r) => $this->isEndpoint($r, $endpoint) && ($callback === null || $callback($r->data(), $r)));

        return $this;
    }

    public function assertNotSent(string $endpoint): static
    {
        Http::assertNotSent(fn (Request $r) => $this->isEndpoint($r, $endpoint));

        return $this;
    }

    /**
     * A correctly signed DMN payload (defaults to an approved Sale) to POST at
     * route('simply-connect.webhook') in your tests.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function fakeWebhook(array $params = []): array
    {
        $params += [
            'ppp_status' => 'OK',
            'Status' => 'APPROVED',
            'transactionType' => 'Sale',
            'TransactionID' => (string) random_int(1_000_000_000, 9_999_999_999),
            'PPP_TransactionID' => (string) random_int(100_000_000, 999_999_999),
            'totalAmount' => '10.00',
            'currency' => 'USD',
            'responseTimeStamp' => date('Y-m-d.H:i:s'),
            'productId' => '',
            'clientUniqueId' => 'order-1',
            'merchant_unique_id' => 'order-1',
            'payment_method' => 'cc_card',
            'ErrCode' => '0',
            'ExErrCode' => '0',
            'Reason' => '',
            'AuthCode' => '111361',
        ];
        $params['merchant_unique_id'] = $params['clientUniqueId'];
        $params['advanceResponseChecksum'] = Checksum::dmn($params, (string) $this->config->get('simply-connect.secret_key'), (string) $this->config->get('simply-connect.hash_algorithm', 'sha256'));

        return $params;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fakeDefaults(?Request $request = null): array
    {
        $sent = $request?->data() ?? [];
        $echo = array_intersect_key($sent, array_flip(['clientUniqueId', 'clientRequestId', 'userTokenId', 'amount', 'currency', 'merchantId', 'merchantSiteId']));
        $tx = ['status' => 'SUCCESS', 'transactionStatus' => 'APPROVED', 'transactionId' => '1110000000001234567', 'authCode' => '111361', 'gwErrorCode' => 0, 'gwExtendedErrorCode' => 0, 'version' => '1.0'] + $echo;

        return [
            'openOrder' => ['status' => 'SUCCESS', 'sessionToken' => 'fake-session-token', 'orderId' => '123456789', 'internalRequestId' => 1, 'version' => '1.0'] + $echo,
            'updateOrder' => ['status' => 'SUCCESS', 'sessionToken' => $sent['sessionToken'] ?? 'fake-session-token', 'orderId' => $sent['orderId'] ?? '123456789', 'version' => '1.0'] + $echo,
            'getPaymentStatus' => $tx + ['transactionType' => 'Sale', 'paymentOption' => ['card' => ['last4Digits' => '1111', 'cardBrand' => 'visa']]],
            'settleTransaction' => $tx + ['transactionType' => 'Settle'],
            'refundTransaction' => $tx + ['transactionType' => 'Credit'],
            'voidTransaction' => $tx + ['transactionType' => 'Void'],
            'getUserUPOs' => ['status' => 'SUCCESS', 'paymentMethods' => []],
            'deleteUPO' => ['status' => 'SUCCESS'],
            'getMerchantPaymentMethods' => ['status' => 'SUCCESS', 'paymentMethods' => []],
        ];
    }

    private function isEndpoint(Request $request, string $endpoint): bool
    {
        return str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/' . $endpoint . '.do');
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->client->{$method}(...$arguments);
    }
}
