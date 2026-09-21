<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Contracts;

/**
 * Transport-agnostic analytics publisher.
 *
 * PoC default is structured-log / null. A later RudderStack adapter can implement
 * this contract without changing listeners.
 */
interface EventPublisher
{
    /**
     * @param  array<string, mixed>  $envelope  Canonical tenant-aware envelope
     */
    public function publish(array $envelope): void;
}
