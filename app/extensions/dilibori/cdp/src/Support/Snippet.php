<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Support;

use Igniter\User\Facades\Auth;
use Illuminate\Contracts\View\View;

final class Snippet
{
    public static function view(): View
    {
        $driver = strtolower((string) config('dilibori-cdp.driver', 'rudderstack'));
        $customer = null;
        try {
            $customer = Auth::customer();
        } catch (\Throwable) {
        }

        return view('dilibori.cdp::snippet', [
            'driver' => $driver,
            'anonymousIdCookie' => (string) config('dilibori-cdp.anonymous_id_cookie', 'dilibori_anonymous_id'),
            'anonymousId' => Identity::anonymousId(),
            'userId' => Identity::userId(),
            'traits' => Identity::traitsFromCustomer($customer),
            'context' => EventContext::baseProperties(),
            'rudderstack' => config('dilibori-cdp.drivers.rudderstack', []),
        ]);
    }

    public static function render(): string
    {
        $driver = strtolower((string) config('dilibori-cdp.driver', 'rudderstack'));
        if (in_array($driver, ['null', 'none', 'disabled'], true)) {
            return '';
        }

        return self::view()->render();
    }
}
