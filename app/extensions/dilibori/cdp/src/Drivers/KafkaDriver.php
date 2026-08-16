<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Drivers;

use Dilibori\Cdp\Contracts\EventClient;
use RuntimeException;

/**
 * Placeholder — produce snake_case Kafka topics from Proper Case events later.
 * Set EVENT_CLIENT_DRIVER=kafka after filling config dilibori-cdp.drivers.kafka.
 */
class KafkaDriver implements EventClient
{
    public function __construct()
    {
        throw new RuntimeException('KafkaDriver is not implemented yet. Use EVENT_CLIENT_DRIVER=rudderstack.');
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
