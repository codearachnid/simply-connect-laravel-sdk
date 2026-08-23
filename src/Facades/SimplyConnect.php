<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \SimplyConnect\Client client()
 * @method static \SimplyConnect\Response\OpenOrderResponse openOrder(array<string, mixed> $params)
 * @method static \SimplyConnect\Response\OpenOrderResponse updateOrder(array<string, mixed> $params)
 * @method static \SimplyConnect\Checkout\CheckoutConfig checkout(\SimplyConnect\Response\OpenOrderResponse|string $order, array<string, mixed> $options = [])
 * @method static \SimplyConnect\Response\TransactionResponse getPaymentStatus(string $sessionToken)
 * @method static \SimplyConnect\Response\TransactionResponse settle(array<string, mixed> $params)
 * @method static \SimplyConnect\Response\TransactionResponse refund(array<string, mixed> $params)
 * @method static \SimplyConnect\Response\TransactionResponse void(array<string, mixed> $params)
 * @method static \SimplyConnect\Response\ApiResponse getMerchantPaymentMethods(string $sessionToken, array<string, mixed> $params = [])
 * @method static \SimplyConnect\Response\ApiResponse getUserPaymentOptions(string $userTokenId)
 * @method static \SimplyConnect\Response\ApiResponse deleteUserPaymentOption(string $userTokenId, string $userPaymentOptionId)
 * @method static \SimplyConnect\Response\ApiResponse call(string $endpoint, array<string, mixed> $params, ?list<string> $checksumOrder = \SimplyConnect\Checksum::ORDER)
 * @method static \SimplyConnect\Webhook\Dmn webhook(\Illuminate\Http\Request|array<string, mixed>|null $input = null)
 * @method static string|null webhookUrl()
 * @method static string scriptUrl()
 * @method static \SimplyConnect\Environment environment()
 * @method static \SimplyConnect\Laravel\SimplyConnect fake(array<string, mixed> $responses = [])
 * @method static \SimplyConnect\Laravel\SimplyConnect assertSent(string $endpoint, ?callable $callback = null)
 * @method static \SimplyConnect\Laravel\SimplyConnect assertNotSent(string $endpoint)
 * @method static \SimplyConnect\Laravel\SimplyConnect assertNothingSent()
 * @method static array<string, mixed> fakeWebhook(array<string, mixed> $params = [])
 *
 * @see \SimplyConnect\Laravel\SimplyConnect
 */
final class SimplyConnect extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \SimplyConnect\Laravel\SimplyConnect::class;
    }
}
