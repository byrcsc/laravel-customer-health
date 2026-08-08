<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Tests\Stubs;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class TestActor extends Authenticatable
{
    public $timestamps = false;

    protected $table = 'test_actors';

    protected $guarded = [];
}
