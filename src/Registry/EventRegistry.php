<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Registry;

use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidEventDefinitionException;
use ByRcsc\LaravelCustomerHealth\Exceptions\UnregisteredEventException;

final class EventRegistry
{
    /** @var array<string, class-string<ProductEvent>> */
    private array $classesByName = [];

    /** @var array<class-string<ProductEvent>, string> */
    private array $namesByClass = [];

    /** @var array<string, list<class-string<ProductEvent>>> */
    private array $eventsByFeature = [];

    /** @var list<class-string<ProductEvent>> */
    private array $milestoneEvents = [];

    /**
     * @param  list<class-string<ProductEvent>>  $eventClasses
     */
    public function __construct(array $eventClasses)
    {
        foreach ($eventClasses as $eventClass) {
            if (! is_subclass_of($eventClass, ProductEvent::class)) {
                throw InvalidEventDefinitionException::forClass($eventClass);
            }

            $name = $eventClass::name();

            if (isset($this->classesByName[$name])) {
                throw InvalidEventDefinitionException::forDuplicateName($name);
            }

            $this->classesByName[$name] = $eventClass;
            $this->namesByClass[$eventClass] = $name;
            $this->eventsByFeature[$eventClass::$feature][] = $eventClass;

            if ($eventClass::$milestone) {
                $this->milestoneEvents[] = $eventClass;
            }
        }
    }

    /** @return class-string<ProductEvent> */
    public function classFor(string $name): string
    {
        return $this->classesByName[$name]
            ?? throw UnregisteredEventException::forName($name);
    }

    /**
     * @param  class-string<ProductEvent>  $eventClass
     */
    public function nameFor(string $eventClass): string
    {
        return $this->namesByClass[$eventClass]
            ?? throw UnregisteredEventException::forClass($eventClass);
    }

    /** @return list<class-string<ProductEvent>> */
    public function eventsForFeature(string $feature): array
    {
        return $this->eventsByFeature[$feature] ?? [];
    }

    /** @return list<class-string<ProductEvent>> */
    public function milestoneEvents(): array
    {
        return $this->milestoneEvents;
    }

    /** @return array<string, list<class-string<ProductEvent>>> */
    public function features(): array
    {
        return $this->eventsByFeature;
    }
}
