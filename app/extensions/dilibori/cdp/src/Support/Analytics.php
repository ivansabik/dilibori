<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Support;

use Dilibori\Cdp\Contracts\EventClient;
use Dilibori\Cdp\Contracts\EventPublisher;

/**
 * Thin helper so listeners call Analytics::track(...) without caring about the driver.
 */
class Analytics
{
    public static function client(): EventClient
    {
        return app(EventClient::class);
    }

    public static function publisher(): EventPublisher
    {
        return app(EventPublisher::class);
    }

    /**
     * @param  array<string, mixed>  $traits
     */
    public static function identify(string $userId, array $traits = [], ?string $anonymousId = null): void
    {
        $anonymousId ??= Identity::anonymousId();
        self::client()->identify(
            $userId,
            $traits,
            $anonymousId,
            ['locale' => EventContext::baseProperties()['locale'] ?? null],
        );
        self::publisher()->publish(EventContext::envelope('Identify', $traits, $userId, $anonymousId));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function track(string $event, array $properties = []): void
    {
        $userId = Identity::userId();
        $anonymousId = Identity::anonymousId();
        $merged = EventContext::merge($properties);

        self::client()->track($event, $merged, $userId, $anonymousId);
        self::publisher()->publish(EventContext::envelope($event, $properties, $userId, $anonymousId));
    }
}
