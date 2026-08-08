<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\ValueObjects;

use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use Carbon\CarbonImmutable;

final readonly class Progress
{
    /**
     * @param  list<class-string<ProductEvent>>  $steps
     * @param  array<class-string<ProductEvent>, CarbonImmutable>  $completed
     */
    public function __construct(private array $steps, private array $completed) {}

    public function completedSteps(): int
    {
        return count($this->completed);
    }

    public function totalSteps(): int
    {
        return count($this->steps);
    }

    public function percent(): int
    {
        return (int) floor(($this->completedSteps() / $this->totalSteps()) * 100);
    }

    /** @return class-string<ProductEvent>|null */
    public function currentStep(): ?string
    {
        foreach ($this->steps as $step) {
            if (! isset($this->completed[$step])) {
                return $step;
            }
        }

        return null;
    }

    public function isComplete(): bool
    {
        return $this->completedSteps() === $this->totalSteps();
    }

    public function stalledSince(): ?CarbonImmutable
    {
        if ($this->completed === [] || $this->isComplete()) {
            return null;
        }

        return collect($this->completed)->sortDesc()->first();
    }
}
