<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Data;

use ByRcsc\LaravelCustomerHealth\Events\ProductEvent;
use ByRcsc\LaravelCustomerHealth\Exceptions\InvalidEventPropertiesException;
use ByRcsc\LaravelCustomerHealth\Registry\EventRegistry;

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
        $subject = MorphIdentity::from($event->subject, 'subject');
        $actor = $event->actor === null ? null : MorphIdentity::from($event->actor, 'actor');
        self::ensurePrimitive($event->properties);

        return new self(
            subjectType: $subject->type,
            subjectId: $subject->id,
            actorType: $actor?->type,
            actorId: $actor?->id,
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
