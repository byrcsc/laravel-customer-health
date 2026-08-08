<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Events;

use ByRcsc\LaravelCustomerHealth\Models\ProductEventRecord;

final readonly class ProductEventRecorded
{
    public function __construct(public ProductEventRecord $record) {}
}
