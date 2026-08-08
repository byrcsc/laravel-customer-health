<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Facades;

use ByRcsc\LaravelCustomerHealth\CustomerHealthManager;
use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void track(ProductEvent $event)
 * @method static array<string, list<class-string<ProductEvent>>> features()
 *
 * @see CustomerHealthManager
 */
final class CustomerHealth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CustomerHealthManager::class;
    }
}
