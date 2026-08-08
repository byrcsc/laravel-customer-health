<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Tests\Stubs;

use ByRcsc\LaravelCustomerHealth\Concerns\TracksCustomerHealth;
use ByRcsc\LaravelCustomerHealth\Contracts\Trackable;
use Illuminate\Database\Eloquent\Model;

final class TestSubject extends Model implements Trackable
{
    use TracksCustomerHealth;

    public $timestamps = false;

    protected $table = 'test_subjects';

    protected $guarded = [];
}
