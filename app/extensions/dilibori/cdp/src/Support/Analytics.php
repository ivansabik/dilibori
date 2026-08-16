<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Support;

use Dilibori\Cdp\Contracts\EventClient;

/**
 * Thin helper so listeners call Analytics::track(...) without caring about the driver.
 */
class Analytics
{
    public static function client(): EventClient
    {
        return app(EventClient::class);
    }

    /**
     * @param  array<string, mixed>  $traits
     */
    public static function identify(string $userId, array $traits = [], ?string $anonymousId = null): void
    {
        self::client()->identify(
            $userId,
            $traits,
            $anonymousId ?? Identity::anonymousId(),
            ['locale' => EventContext::baseProperties()['locale'] ?? null],
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function track(string $event, array $properties = []): void
    {
        self::client()->track(
            $event,
            EventContext::merge($properties),
            Identity::userId(),
            Identity::anonymousId(),
        );
    }
}
