<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Support;

use Igniter\Local\Facades\Location;

class EventContext
{
    /**
     * Shared properties for nearly every storefront event.
     *
     * @return array<string, mixed>
     */
    public static function baseProperties(): array
    {
        $props = [
            'locale' => app()->getLocale(),
            'environment' => getenv('APP_ENV') ?: 'local',
        ];

        try {
            $location = Location::current();
            if ($location) {
                $props['location_id'] = (string) $location->getKey();
                $props['location_name'] = (string) ($location->location_name ?? '');
            }
        } catch (\Throwable) {
        }

        try {
            if (function_exists('currency')) {
                $currency = currency()->getUserCurrency()
                    ?? currency()->getDefault()?->currency_code
                    ?? null;
                if ($currency) {
                    $props['currency'] = strtoupper((string) $currency);
                }
            }
        } catch (\Throwable) {
        }

        if (empty($props['currency'])) {
            $props['currency'] = 'USD';
        }

        try {
            $orderType = Location::orderType();
            if ($orderType) {
                $props['order_type'] = (string) $orderType;
            }
        } catch (\Throwable) {
        }

        return array_filter($props, static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Canonical envelope for future analytics adapters.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function envelope(string $event, array $properties = [], ?string $userId = null, ?string $anonymousId = null): array
    {
        $merged = self::merge($properties);
        $userIdOut = ($userId !== null && $userId !== '') ? $userId : null;

        return [
            'environment' => $merged['environment'] ?? (getenv('APP_ENV') ?: 'local'),
            'event' => $event,
            'timestamp' => gmdate('c'),
            'user_id' => $userIdOut,
            'anonymous_id' => $anonymousId,
            'properties' => $merged,
            'context' => self::requestContext(),
        ];
    }

    /**
     * Segment-style request context for backend events (user agent, device, page).
     * Empty when there is no HTTP request (CLI, queue).
     *
     * @return array<string, mixed>
     */
    public static function requestContext(): array
    {
        try {
            $request = request();
        } catch (\Throwable) {
            return [];
        }

        if ($request === null) {
            return [];
        }

        $ua = substr((string) $request->userAgent(), 0, 512);
        $chUa = trim((string) $request->header('Sec-CH-UA', ''));
        $chMobile = strtolower(trim((string) $request->header('Sec-CH-UA-Mobile', '')));
        $chPlatform = trim((string) $request->header('Sec-CH-UA-Platform', ''), " \t\"'");

        $context = [
            'locale' => app()->getLocale(),
        ];

        if ($ua !== '') {
            $context['userAgent'] = $ua;
        }

        $userAgentData = array_filter([
            'brands' => $chUa !== '' ? $chUa : null,
            'mobile' => $chMobile === '?1' ? true : ($chMobile === '?0' ? false : null),
            'platform' => $chPlatform !== '' ? $chPlatform : null,
        ], static fn ($v) => $v !== null && $v !== '');
        if ($userAgentData !== []) {
            $context['userAgentData'] = $userAgentData;
        }

        $deviceType = self::guessDeviceType($ua, $chMobile === '?1');
        if ($deviceType !== null) {
            $context['device'] = ['type' => $deviceType];
        }

        $osName = self::guessOsName($ua, $chPlatform);
        if ($osName !== null) {
            $context['os'] = ['name' => $osName];
        }

        $ip = $request->ip();
        if (is_string($ip) && $ip !== '') {
            $context['ip'] = $ip;
        }

        $page = array_filter([
            'path' => '/'.ltrim($request->path(), '/'),
            'url' => $request->fullUrl(),
            'referrer' => $request->headers->get('referer') ?: null,
        ], static fn ($v) => $v !== null && $v !== '');
        if ($page !== []) {
            $context['page'] = $page;
        }

        return $context;
    }

    /**
     * ponytail: coarse device/OS from UA regex + Client Hints; no browser version.
     * Upgrade: UA parser package, or parse in a Rudder transform / warehouse.
     */
    private static function guessDeviceType(string $ua, bool $chMobile): ?string
    {
        if ($chMobile || preg_match('/Mobile|Android.*Mobile|iPhone|iPod|webOS|BlackBerry|IEMobile/i', $ua)) {
            return 'mobile';
        }

        if (preg_match('/iPad|Android(?!.*Mobile)|Tablet|Silk/i', $ua)) {
            return 'tablet';
        }

        if ($ua !== '') {
            return 'desktop';
        }

        return null;
    }

    private static function guessOsName(string $ua, string $chPlatform): ?string
    {
        $platform = strtolower($chPlatform);
        $map = [
            'android' => 'Android',
            'ios' => 'iOS',
            'windows' => 'Windows',
            'macos' => 'macOS',
            'mac os' => 'macOS',
            'linux' => 'Linux',
            'chrome os' => 'Chrome OS',
        ];
        foreach ($map as $needle => $name) {
            if ($platform !== '' && str_contains($platform, $needle)) {
                return $name;
            }
        }

        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            return 'iOS';
        }
        if (preg_match('/Android/i', $ua)) {
            return 'Android';
        }
        if (preg_match('/Windows/i', $ua)) {
            return 'Windows';
        }
        if (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            return 'macOS';
        }
        if (preg_match('/Linux/i', $ua)) {
            return 'Linux';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function merge(array $properties): array
    {
        return array_merge(self::baseProperties(), $properties);
    }
}
