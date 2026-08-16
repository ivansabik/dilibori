<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Drivers;

use Dilibori\Cdp\Contracts\EventClient;
use RuntimeException;

/**
 * Placeholder — implement when wiring Segment HTTP API.
 * Set EVENT_CLIENT_DRIVER=segment after filling config dilibori-cdp.drivers.segment.
 */
class SegmentDriver implements EventClient
{
    public function __construct()
    {
        throw new RuntimeException('SegmentDriver is not implemented yet. Use EVENT_CLIENT_DRIVER=rudderstack.');
    }

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
