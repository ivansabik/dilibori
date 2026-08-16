<?php

declare(strict_types=1);

namespace Taconazo\Locale\Http\Middleware;

use Closure;
use Igniter\Local\Facades\Location;
use Illuminate\Http\Request;
use ReflectionObject;

/**
 * Switch storefront currency from the current location:
 * Tecnológico (Cd Juárez) → MXN, everything else → USD.
 */
class SetLocationCurrency
{
    public function handle(Request $request, Closure $next): mixed
    {
        $code = 'USD';
        $location = Location::current();
        if ($location && str_contains((string) $location->permalink_slug, 'tecnologico')) {
            $code = 'MXN';
        }

        $this->forceCurrency($code);

        return $next($request);
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
