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
            'tenant_id' => getenv('TENANT_ID') ?: null,
            'deployment_id' => getenv('DEPLOYMENT_ID') ?: null,
            'cell_id' => getenv('CELL_ID') ?: 'local-cell',
            'environment' => getenv('DEPLOYMENT_ENVIRONMENT') ?: (getenv('APP_ENV') ?: 'local'),
        ];

        try {
            $location = Location::current();
            if ($location) {
                $localId = (string) $location->getKey();
                $props['location_id'] = $localId;
                $props['location_name'] = (string) ($location->location_name ?? '');
                if (!empty($props['tenant_id'])) {
                    $props['namespaced_location_id'] = $props['tenant_id'].':'.$localId;
                }
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
        $tenantId = $merged['tenant_id'] ?? (getenv('TENANT_ID') ?: null);

        $namespacedUserId = null;
        if ($userId !== null && $userId !== '' && $tenantId) {
            $namespacedUserId = $tenantId.':'.$userId;
        } elseif ($userId !== null && $userId !== '') {
            $namespacedUserId = $userId;
        }

        return [
            'tenant_id' => $tenantId,
            'deployment_id' => $merged['deployment_id'] ?? (getenv('DEPLOYMENT_ID') ?: null),
            'cell_id' => $merged['cell_id'] ?? (getenv('CELL_ID') ?: 'local-cell'),
            'environment' => $merged['environment'] ?? (getenv('DEPLOYMENT_ENVIRONMENT') ?: 'development'),
            'event' => $event,
            'timestamp' => gmdate('c'),
            'user_id' => $namespacedUserId,
            'anonymous_id' => $anonymousId,
            'properties' => $merged,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function merge(array $properties): array
    {
        return array_merge(self::baseProperties(), $properties);
    }
}
