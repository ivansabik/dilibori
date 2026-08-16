<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Contracts;

/**
 * Transport-agnostic analytics client.
 *
 * Swap drivers via EVENT_CLIENT_DRIVER (rudderstack, segment, kafka, null, …)
 * without changing listeners or event names.
 */
interface EventClient
{
    /**
     * Associate traits with a known user (and optionally stitch anonymousId).
     *
     * @param  array<string, mixed>  $traits
     * @param  array<string, mixed>  $context
     */
    public function identify(string $userId, array $traits = [], ?string $anonymousId = null, array $context = []): void;

    /**
     * Record a named user action.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context
     */
    public function track(string $event, array $properties = [], ?string $userId = null, ?string $anonymousId = null, array $context = []): void;

    /**
     * Record a page view (optional for server-side drivers).
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $context
     */
    public function page(string $name, array $properties = [], ?string $userId = null, ?string $anonymousId = null, array $context = []): void;
}
