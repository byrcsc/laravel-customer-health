<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Registry;

use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidScoreDefinitionException;
use ByRcsc\LaravelCustomerHealth\Scoring\HealthScore;
use ByRcsc\LaravelCustomerHealth\Scoring\Signal;

final class HealthScoreRegistry
{
    /** @var array<string, HealthScore> */
    private array $byName = [];

    /** @param list<class-string<HealthScore>> $classes */
    public function __construct(array $classes)
    {
        foreach ($classes as $class) {
            if (! is_subclass_of($class, HealthScore::class)) {
                throw InvalidScoreDefinitionException::invalid("Health score [{$class}] must extend HealthScore.");
            }

            $score = new $class;
            $name = $class::name();

            if ($name === '' || isset($this->byName[$name])) {
                throw InvalidScoreDefinitionException::invalid("Health score name [{$name}] must be non-empty and unique.");
            }

            $this->weightedSignals($score);
            $this->statesFor($score);
            $this->byName[$name] = $score;
        }
    }

    public function resolve(?string $name = null): HealthScore
    {
        if ($name === null) {
            $score = reset($this->byName);

            if ($score instanceof HealthScore) {
                return $score;
            }
        }

        if ($name !== null && isset($this->byName[$name])) {
            return $this->byName[$name];
        }

        foreach ($this->byName as $score) {
            if ($score::class === $name) {
                return $score;
            }
        }

        throw InvalidScoreDefinitionException::invalid('The requested health score is not registered.');
    }

    /** @return list<HealthScore> */
    public function all(): array
    {
        return array_values($this->byName);
    }

    /** @return list<array{signal: Signal, weight: float}> */
    public function weightedSignals(HealthScore $score): array
    {
        $name = $score::name();
        /** @var array<array-key, mixed> $signals */
        $signals = $score->signals();

        if ($signals === []) {
            throw InvalidScoreDefinitionException::invalid("Health score [{$name}] must declare at least one signal.");
        }

        $weighted = [];
        foreach ($signals as $signal) {
            if (! $signal instanceof Signal) {
                throw InvalidScoreDefinitionException::invalid("Health score [{$name}] contains an invalid signal.");
            }

            $weight = $signal->weight();
            if (! is_finite($weight) || $weight <= 0) {
                throw InvalidScoreDefinitionException::invalid("Health score [{$name}] signal weights must be finite and greater than zero.");
            }

            $weighted[] = ['signal' => $signal, 'weight' => $weight];
        }

        $totalWeight = array_sum(array_column($weighted, 'weight'));
        if (! is_finite($totalWeight)) {
            throw InvalidScoreDefinitionException::invalid("Health score [{$name}] signal weight total must be finite.");
        }

        return $weighted;
    }

    public function stateFor(HealthScore $score, int $value): string
    {
        $states = $this->statesFor($score);
        asort($states, SORT_NUMERIC);
        $state = array_key_first($states);

        foreach ($states as $name => $threshold) {
            if ($value < $threshold) {
                break;
            }

            $state = $name;
        }

        return (string) $state;
    }

    /** @return array<string, int> */
    private function statesFor(HealthScore $score): array
    {
        $name = $score::name();
        $states = $score->states();

        if ($states === [] || ! in_array(0, $states, true)) {
            throw InvalidScoreDefinitionException::invalid("Health score [{$name}] must declare a state starting at zero.");
        }

        foreach ($states as $state => $threshold) {
            if ($state === '' || $threshold < 0 || $threshold > 100) {
                throw InvalidScoreDefinitionException::invalid("Health score [{$name}] has an invalid state threshold.");
            }
        }

        if (count($states) !== count(array_unique($states, SORT_REGULAR))) {
            throw InvalidScoreDefinitionException::invalid("Health score [{$name}] state thresholds must be unique.");
        }

        return $states;
    }
}
