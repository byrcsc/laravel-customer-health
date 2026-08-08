<?php

declare(strict_types=1);

namespace ByRcsc\LaravelCustomerHealth\Support;

/**
 * The single source of truth for what this package's tables are called: the
 * models and migrations resolve through here, never through a literal string.
 */
final class TableNames
{
    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'events' => 'customer_health_events',
        'milestones' => 'customer_health_milestones',
        'scores' => 'customer_health_scores',
        'summaries' => 'customer_health_summaries',
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    public static function events(): string
    {
        return self::for('events');
    }

    public static function milestones(): string
    {
        return self::for('milestones');
    }

    public static function scores(): string
    {
        return self::for('scores');
    }

    public static function summaries(): string
    {
        return self::for('summaries');
    }

    public static function default(string $key): string
    {
        return self::DEFAULTS[$key] ?? $key;
    }

    /**
     * The fallback covers a published config written against an older version
     * of the package, with no entry for a table added since.
     */
    public static function for(string $key): string
    {
        /** @var mixed $configured */
        $configured = function_exists('config')
            ? config("customer-health.table_names.{$key}")
            : null;

        return is_string($configured) && $configured !== ''
            ? $configured
            : self::default($key);
    }
}
