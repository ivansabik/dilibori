<?php

declare(strict_types=1);

namespace Taconazo\Locale\Http\Middleware;

use Closure;
use Igniter\Local\Facades\Location;
use Igniter\Local\Models\LocationSettings;
use Illuminate\Http\Request;
use ReflectionObject;

/**
 * Switch storefront currency from the current location settings.
 * Seed stores dilibori_currency_code on location options.
 */
class SetLocationCurrency
{
    public function handle(Request $request, Closure $next): mixed
    {
        $code = $this->currencyForCurrentLocation() ?? 'USD';
        $this->forceCurrency($code);

        return $next($request);
    }

    protected function currencyForCurrentLocation(): ?string
    {
        $location = Location::current();
        if (!$location) {
            return null;
        }

        try {
            $settings = LocationSettings::instance($location, 'options');
            $code = $settings->getAttribute('dilibori_currency_code');
            if (is_string($code) && $code !== '') {
                return strtoupper($code);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    protected function forceCurrency(string $code): void
    {
        if (! function_exists('currency')) {
            return;
        }

        if (! currency()->isActive($code)) {
            return;
        }

        currency()->setUserCurrency($code);
        config(['igniter-currency.default' => $code]);

        $instance = currency();
        $reflection = new ReflectionObject($instance);
        if ($reflection->hasProperty('config')) {
            $property = $reflection->getProperty('config');
            $property->setAccessible(true);
            $config = $property->getValue($instance);
            if (is_array($config)) {
                $config['default'] = $code;
                $property->setValue($instance, $config);
            }
        }

        session(['igniter.currency' => $code]);
    }
}
