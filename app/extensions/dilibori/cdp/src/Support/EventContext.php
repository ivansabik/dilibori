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
     * @return array<string, mixed>
     */
    public static function merge(array $properties): array
    {
        return array_merge(self::baseProperties(), $properties);
    }
}
