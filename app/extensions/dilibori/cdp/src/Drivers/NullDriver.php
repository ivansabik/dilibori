<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Drivers;

use Dilibori\Cdp\Contracts\EventClient;

/**
 * No-op driver used when CDP is disabled or the configured driver is unavailable.
 */
final class NullDriver implements EventClient
{
    public function identify(string $userId, array $traits = [], ?string $anonymousId = null, array $context = []): void
    {
    }

    public function track(string $event, array $properties = [], ?string $userId = null, ?string $anonymousId = null, array $context = []): void
    {
    }

    public function page(string $name, array $properties = [], ?string $userId = null, ?string $anonymousId = null, array $context = []): void
    {
    }
}
