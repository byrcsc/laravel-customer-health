<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Data;

use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidEventPropertiesException;
use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidTrackableException;
use ByRcsc\LaravelCustomerHealth\Registry\EventRegistry;
use Illuminate\Database\Eloquent\Model;

final readonly class ProductEventData
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public string $subjectType,
        public string $subjectId,
        public ?string $actorType,
        public ?string $actorId,
        public string $name,
        public string $feature,
        public bool $milestone,
        public array $properties,
        public string $occurredAt,
    ) {}

    public static function from(ProductEvent $event, EventRegistry $events): self
    {
        $subject = self::modelFor($event->subject, 'subject');
        $actor = $event->actor === null ? null : self::modelFor($event->actor, 'actor');
        self::ensurePrimitive($event->properties);

        return new self(
            subjectType: self::morphTypeFor($subject),
            subjectId: self::keyFor($subject),
            actorType: $actor === null ? null : self::morphTypeFor($actor),
            actorId: $actor === null ? null : self::keyFor($actor),
            name: $events->nameFor($event::class),
            feature: $event::$feature,
            milestone: $event::$milestone,
            properties: $event->properties,
            occurredAt: $event->occurredAt->format('Y-m-d H:i:s.u'),
        );
    }

    /**
     * @return array{subject_type: string, subject_id: string, actor_type: string|null, actor_id: string|null}
     */
    public function identityAttributes(): array
    {
        return [
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
        ];
    }

    private static function modelFor(object $value, string $role): Model
    {
        if (! $value instanceof Model) {
            throw InvalidTrackableException::notEloquent($role);
        }

        if ($value->getKey() === null) {
            throw InvalidTrackableException::notPersisted($role);
        }

        return $value;
    }

    private static function morphTypeFor(Model $model): string
    {
        $morphType = $model->getMorphClass();

        if ($morphType === '') {
            throw InvalidTrackableException::invalidMorphType();
        }

        return $morphType;
    }

    private static function keyFor(Model $model): string
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw InvalidTrackableException::invalidKey();
        }

        return (string) $key;
    }

    private static function ensurePrimitive(mixed $value, string $path = 'properties'): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }

        if (! is_array($value)) {
            throw InvalidEventPropertiesException::nonPrimitive($path);
        }

        foreach ($value as $key => $child) {
            self::ensurePrimitive($child, "{$path}.{$key}");
        }
    }
}
