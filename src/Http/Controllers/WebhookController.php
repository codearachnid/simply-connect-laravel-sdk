<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SimplyConnect\Exception\InvalidChecksumException;
use SimplyConnect\Laravel\Events\WebhookReceived;
use SimplyConnect\Laravel\SimplyConnect;

final class WebhookController
{
    public function __invoke(Request $request, SimplyConnect $simplyConnect): Response
    {
        try {
            $dmn = $simplyConnect->webhook($request);
        } catch (InvalidChecksumException $e) {
            abort(400, $e->getMessage());
        }

        WebhookReceived::dispatch($dmn);

        return new Response('OK');
    }
}
