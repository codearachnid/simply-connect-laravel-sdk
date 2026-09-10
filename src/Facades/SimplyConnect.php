<?php

declare(strict_types=1);

namespace SimplyConnect\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \SimplyConnect\Laravel\SimplyConnect
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
