<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Drivers;

use Dilibori\Cdp\Contracts\EventPublisher;

/**
 * PoC publisher: writes redacted JSON envelopes to the PHP error log.
 */
class StructuredLogPublisher implements EventPublisher
{
    public function publish(array $envelope): void
    {
        $safe = $envelope;
        unset($safe['properties']['password'], $safe['properties']['token'], $safe['properties']['authorization']);
        error_log('dilibori.event '.json_encode($safe, JSON_UNESCAPED_SLASHES));
    }
}
