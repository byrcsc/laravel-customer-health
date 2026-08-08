<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Scoring;

use Illuminate\Support\Str;

abstract class HealthScore
{
    public static string $name;

    /** @return list<Signal> */
    abstract public function signals(): array;

    /** @return array<string, int> */
    abstract public function states(): array;

    public static function name(): string
    {
        return isset(static::$name) ? static::$name : Str::snake(class_basename(static::class));
    }
}
