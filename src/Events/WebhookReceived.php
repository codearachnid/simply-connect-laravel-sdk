<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use SimplyConnect\Webhook\Dmn;

/**
 * Fired for every authenticated DMN. Inspect $dmn->transactionType() (Sale,
 * Auth, Settle, Credit, Void, Chargeback, ...) and $dmn->isApproved().
 * Nuvei retries for 24h unless it receives HTTP 200, so a throwing listener
 * means redelivery; make fulfilment idempotent on $dmn->transactionId().
 */
final class WebhookReceived
{
    use Dispatchable;

    public function __construct(public readonly Dmn $dmn)
    {
    }
}
