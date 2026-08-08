<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Data;

use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidTrackableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final readonly class MorphIdentity
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function from(object $value, string $role): self
    {
        if (! $value instanceof Model) {
            throw InvalidTrackableException::notEloquent($role);
        }

        $key = $value->getKey();

        if ($key === null) {
            throw InvalidTrackableException::notPersisted($role);
        }

        if (! is_int($key) && ! is_string($key)) {
            throw InvalidTrackableException::invalidKey();
        }

        $morphType = $value->getMorphClass();

        if ($morphType === '') {
            throw InvalidTrackableException::invalidMorphType();
        }

        return new self($morphType, (string) $key);
    }

    public function resolve(?string $connection = null): ?Model
    {
        $modelClass = Relation::getMorphedModel($this->type) ?? $this->type;

        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        $model = new $modelClass;

        if ($connection !== null) {
            $model->setConnection($connection);
        }

        return $model->newQuery()->find($this->id);
    }
}
