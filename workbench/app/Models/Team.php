<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use ByRcsc\LaravelCustomerHealth\Concerns\TracksCustomerHealth;
use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use Illuminate\Database\Eloquent\Model;

final class Team extends Model implements Trackable
{
    use TracksCustomerHealth;

    protected $connection = 'tenant';

    protected $guarded = [];
}
