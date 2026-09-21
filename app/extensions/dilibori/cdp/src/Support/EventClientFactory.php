<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Support;

use Dilibori\Cdp\Contracts\EventClient;
use Dilibori\Cdp\Contracts\EventPublisher;
use Dilibori\Cdp\Drivers\NullDriver;
use Dilibori\Cdp\Drivers\NullPublisher;
use Dilibori\Cdp\Drivers\RudderStackDriver;
use Dilibori\Cdp\Drivers\StructuredLogPublisher;

class EventClientFactory
{
    public static function make(): EventClient
    {
        if (! config('dilibori-cdp.enabled', true)) {
            return new NullDriver;
        }

        $driver = strtolower((string) config('dilibori-cdp.driver', 'null'));

        return match ($driver) {
            'rudderstack' => self::rudderstack(),
            'log', 'structured-log' => new NullDriver,
            'null', 'none', 'disabled' => new NullDriver,
            'segment', 'kafka' => self::unsupported($driver),
            default => self::unsupported($driver),
        };
    }

    public static function makePublisher(): EventPublisher
    {
        if (! config('dilibori-cdp.enabled', true)) {
            return new NullPublisher;
        }

        $driver = strtolower((string) config('dilibori-cdp.driver', 'null'));

        return match ($driver) {
            'log', 'structured-log' => new StructuredLogPublisher,
            'null', 'none', 'disabled' => new NullPublisher,
            // Future: rudderstack publisher adapter can wrap HTTP delivery here.
            default => new StructuredLogPublisher,
        };
    }

    private static function rudderstack(): EventClient
    {
        $cfg = config('dilibori-cdp.drivers.rudderstack', []);
        $url = rtrim((string) ($cfg['data_plane_url'] ?? ''), '/');
        $key = (string) ($cfg['http_write_key'] ?? '');

        if ($url === '' || $key === '') {
            error_log('CDP RudderStack driver missing data plane URL or write key; using NullDriver');

            return new NullDriver;
        }

        return new RudderStackDriver(
            dataPlaneUrl: $url,
            writeKey: $key,
            timeout: (float) ($cfg['timeout'] ?? 0.5),
        );
    }

    private static function unsupported(string $driver): EventClient
    {
        error_log("CDP event driver [{$driver}] is not implemented yet; using NullDriver");

        return new NullDriver;
    }
}
