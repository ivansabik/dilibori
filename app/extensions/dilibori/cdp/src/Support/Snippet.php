<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Support;

use Igniter\Cart\Facades\Cart;
use Igniter\User\Facades\Auth;
use Illuminate\Contracts\View\View;
use Throwable;

class Snippet
{
    public static function view(): View
    {
        self::maybeTrackCheckoutStarted();

        $driver = strtolower((string) config('dilibori-cdp.driver', 'rudderstack'));
        $customer = null;
        try {
            $customer = Auth::customer();
        } catch (Throwable) {
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

    /**
     * Funnel mid-point: landing on checkout with a cart, not empty-cart bounce
     * or only order save. Once per session (shared with JS + beforeSaveOrder).
     */
    public static function maybeTrackCheckoutStarted(): void
    {
        try {
            if (! config('dilibori-cdp.enabled', true)) {
                return;
            }

            $path = strtolower('/'.ltrim(request()->path(), '/'));
            if (! str_contains($path, 'checkout') || str_contains($path, 'success')) {
                return;
            }

            // Empty cart redirects to menus; that response still hits this hook
            // and must not burn the once-per-session flag.
            if (Cart::count() < 1) {
                return;
            }

            if (session('cdp_checkout_started')) {
                return;
            }

            Analytics::track(Events::CHECKOUT_STARTED, [
                'path' => $path,
                'producer' => 'backend',
                'cart_count' => Cart::count(),
            ]);
            session(['cdp_checkout_started' => true]);
        } catch (Throwable) {
            // Never break storefront HTML for analytics.
        }
    }
}
