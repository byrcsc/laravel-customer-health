<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

final class TestSubject extends Model
{
    public $timestamps = false;

    protected $table = 'test_subjects';

    protected $guarded = [];
}
