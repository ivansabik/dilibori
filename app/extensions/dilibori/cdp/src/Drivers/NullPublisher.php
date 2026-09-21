<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Drivers;

use Dilibori\Cdp\Contracts\EventPublisher;

class NullPublisher implements EventPublisher
{
    public function publish(array $envelope): void
    {
    }
}
