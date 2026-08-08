<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Events;

use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class ProductEvent
{
    public static string $feature;

    public static bool $milestone = false;

    public static string $name;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public readonly Trackable $subject,
        public readonly (Authenticatable&Model)|null $actor = null,
        public readonly array $properties = [],
        ?DateTimeInterface $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($occurredAt)->utc();
    }

    public readonly CarbonImmutable $occurredAt;

    public static function name(): string
    {
        return isset(static::$name)
            ? static::$name
            : Str::snake(class_basename(static::class));
    }
}
