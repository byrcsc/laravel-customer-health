<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Registry;

use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidChecklistDefinitionException;
use ByRcsc\LaravelCustomerHealth\Onboarding\Checklist;

final class ChecklistRegistry
{
    /** @var array<string, Checklist> */
    private array $byName = [];

    /** @var array<string, list<Checklist>> */
    private array $byEventName = [];

    /** @param list<class-string<Checklist>> $classes */
    public function __construct(array $classes, EventRegistry $events)
    {
        foreach ($classes as $class) {
            if (! is_subclass_of($class, Checklist::class)) {
                throw InvalidChecklistDefinitionException::invalid("Checklist [{$class}] must extend Checklist.");
            }

            $checklist = new $class;
            $name = $class::name();
            $steps = $checklist->steps();

            if ($steps === [] || count($steps) !== count(array_unique($steps))) {
                throw InvalidChecklistDefinitionException::invalid("Checklist [{$name}] must contain unique steps.");
            }

            if (isset($this->byName[$name])) {
                throw InvalidChecklistDefinitionException::invalid("Checklist name [{$name}] is registered more than once.");
            }

            foreach ($steps as $step) {
                $events->nameFor($step);

                if (! $step::$milestone) {
                    throw InvalidChecklistDefinitionException::invalid("Checklist step [{$step}] must be a milestone event.");
                }

                $this->byEventName[$step::name()][] = $checklist;
            }

            $this->byName[$name] = $checklist;
        }
    }

    public function resolve(?string $name = null): Checklist
    {
        if ($name === null) {
            $checklist = reset($this->byName);

            if ($checklist instanceof Checklist) {
                return $checklist;
            }
        }

        if ($name !== null && isset($this->byName[$name])) {
            return $this->byName[$name];
        }

        foreach ($this->byName as $checklist) {
            if ($checklist::class === $name) {
                return $checklist;
            }
        }

        throw InvalidChecklistDefinitionException::invalid('The requested onboarding checklist is not registered.');
    }

    /** @return list<Checklist> */
    public function forEvent(string $eventName): array
    {
        return $this->byEventName[$eventName] ?? [];
    }

    /** @return list<Checklist> */
    public function all(): array
    {
        return array_values($this->byName);
    }
}
