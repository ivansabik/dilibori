<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Drivers;

use Dilibori\Cdp\Contracts\EventClient;
use Dilibori\Cdp\Support\EventContext;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Segment-compatible HTTP API against a RudderStack data plane.
 */
class RudderStackDriver implements EventClient
{
    public function __construct(
        private readonly string $dataPlaneUrl,
        private readonly string $writeKey,
        private readonly float $timeout = 0.5,
    ) {
    }

    public function identify(string $userId, array $traits = [], ?string $anonymousId = null, array $context = []): void
    {
        $payload = array_filter([
            'userId' => $userId,
            'anonymousId' => $anonymousId,
            'traits' => $traits ?: null,
            'context' => $context ?: null,
        ], static fn ($value) => $value !== null && $value !== []);

        $this->post('/v1/identify', $payload);
    }

    public function track(string $event, array $properties = [], ?string $userId = null, ?string $anonymousId = null, array $context = []): void
    {
        $payload = array_filter([
            'event' => $event,
            'userId' => $userId,
            'anonymousId' => $anonymousId,
            'properties' => $properties ?: null,
            'context' => $context ?: null,
        ], static fn ($value) => $value !== null && $value !== []);

        if (empty($payload['userId']) && empty($payload['anonymousId'])) {
            $payload['anonymousId'] = 'server-unknown';
        }

        $this->post('/v1/track', $payload);
    }

    public function page(string $name, array $properties = [], ?string $userId = null, ?string $anonymousId = null, array $context = []): void
    {
        $payload = array_filter([
            'name' => $name,
            'userId' => $userId,
            'anonymousId' => $anonymousId,
            'properties' => $properties ?: null,
            'context' => $context ?: null,
        ], static fn ($value) => $value !== null && $value !== []);

        if (empty($payload['userId']) && empty($payload['anonymousId'])) {
            $payload['anonymousId'] = 'server-unknown';
        }

        $this->post('/v1/page', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload): void
    {
        if ($this->writeKey === '' || $this->dataPlaneUrl === '') {
            return;
        }

        $baseUrl = rtrim($this->dataPlaneUrl, '/');
        $url = "{$baseUrl}{$path}";

        $payload['context'] = array_replace_recursive(
            EventContext::requestContext(),
            is_array($payload['context'] ?? null) ? $payload['context'] : [],
        );
        if ($payload['context'] === []) {
            unset($payload['context']);
        }

        try {
            Http::withBasicAuth($this->writeKey, '')
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);
        } catch (Throwable $e) {
            // Never use the Laravel Log facade here: if storage/logs is unwritable,
            // Log::debug throws and would swallow Application Error / other CDP events.
            error_log("CDP RudderStack request failed: {$e->getMessage()}");
        }
    }
}
