<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Concerns;

use ByRcsc\LaravelCustomerHealth\Facades\CustomerHealth;
use ByRcsc\LaravelCustomerHealth\Models\Milestone;
use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait TracksCustomerHealth
{
    /**
     * @return MorphMany<ProductEventRecord, $this>
     */
    public function productEvents(): MorphMany
    {
        return $this->morphMany(ProductEventRecord::class, 'subject');
    }

    /**
     * @return MorphMany<Milestone, $this>
     */
    public function milestones(): MorphMany
    {
        return $this->morphMany(Milestone::class, 'subject');
    }

    public function lastProductActivity(): ?CarbonImmutable
    {
        return CustomerHealth::lastSeen($this);
    }

    public function hasAdopted(string $feature): bool
    {
        return CustomerHealth::hasAdopted($this, $feature);
    }
}
