<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Onboarding;

use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidChecklistDefinitionException;
use Illuminate\Support\Str;

abstract class Checklist
{
    public static string $name;

    /** @return list<class-string<ProductEvent>> */
    abstract public function steps(): array;

    public static function name(): string
    {
        return isset(static::$name) ? static::$name : Str::snake(class_basename(static::class));
    }

    /** @return list<string> */
    final public function stepNames(): array
    {
        return array_map(fn (string $step): string => $step::name(), $this->steps());
    }

    /** @return class-string<ProductEvent> */
    final public function stepForName(string $name): string
    {
        foreach ($this->steps() as $step) {
            if ($step::name() === $name) {
                return $step;
            }
        }

        throw InvalidChecklistDefinitionException::invalid("Event [{$name}] is not a step in checklist [{$this::name()}].");
    }
}
