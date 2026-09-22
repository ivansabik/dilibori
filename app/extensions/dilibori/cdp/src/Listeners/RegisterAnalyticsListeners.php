<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Listeners;

use Dilibori\Cdp\Support\Analytics;
use Dilibori\Cdp\Support\EventContext;
use Dilibori\Cdp\Support\Events;
use Dilibori\Cdp\Support\Identity;
use Igniter\Cart\Models\Order;
use Igniter\User\Models\Customer;
use Illuminate\Support\Facades\Event;
use Throwable;

class RegisterAnalyticsListeners
{
    public static function register(): void
    {
        Event::listen('igniter.user.register', [self::class, 'onRegister']);
        Event::listen('igniter.user.login', [self::class, 'onLogin']);
        Event::listen('igniter.user.logout', [self::class, 'onLogout']);

        Event::listen('cart.added', [self::class, 'onCartAdded']);
        Event::listen('cart.updated', [self::class, 'onCartUpdated']);
        Event::listen('cart.removed', [self::class, 'onCartRemoved']);
        Event::listen('cart.cleared', [self::class, 'onCartCleared']);

        Event::listen('location.current.updated', [self::class, 'onLocationSelected']);
        Event::listen('location.orderType.updated', [self::class, 'onOrderTypeSelected']);
        Event::listen('location.area.updated', [self::class, 'onDeliveryAreaSelected']);
        Event::listen('location.timeslot.updated', [self::class, 'onTimeslotSelected']);

        Event::listen('igniter.checkout.beforeSaveOrder', [self::class, 'onCheckoutStarted']);
        Event::listen('igniter.checkout.beforePayment', [self::class, 'onPaymentStarted']);
        Event::listen('admin.order.paymentProcessed', [self::class, 'onOrderCompleted']);
        Event::listen('igniter.cart.orderStatusAdded', [self::class, 'onOrderStatusUpdated']);
        Event::listen('admin.order.canceled', [self::class, 'onOrderCancelled']);
        Event::listen('admin.order.refundProcessed', [self::class, 'onOrderRefunded']);

        Event::listen('igniter.cart.beforeApplyCoupon', [self::class, 'onCouponEntered']);
        Event::listen('cart.condition.loaded', [self::class, 'onCartConditionLoaded']);
        Event::listen('cart.condition.removed', [self::class, 'onCartConditionRemoved']);
        Event::listen('admin.order.couponRedeemed', [self::class, 'onCouponRedeemed']);

        Event::listen('igniter.reservation.confirmed', [self::class, 'onReservationBooked']);
        Event::listen('igniter.reservation.statusAdded', [self::class, 'onReservationStatusUpdated']);
        Event::listen('igniter.reservation.isFullyBookedOn', [self::class, 'onReservationSlotDenied']);

        Event::listen('eloquent.created: Igniter\Local\Models\Review', [self::class, 'onReviewSubmitted']);
        // firstOrCreate — use Igniter's event so duplicates still track (success toast either way).
        Event::listen('igniter.frontend.subscribed', [self::class, 'onNewsletterSubscribed']);
    }

    public static function onRegister(Customer $customer, array $data = []): void
    {
        self::safe(function () use ($customer): void {
            $userId = (string) $customer->getKey();
            $traits = Identity::traitsFromCustomer($customer);
            $traits['locale'] = EventContext::baseProperties()['locale'] ?? null;

            Analytics::identify($userId, array_filter($traits));
            Analytics::client()->track(
                Events::ACCOUNT_CREATED,
                EventContext::merge([]),
                $userId,
                Identity::anonymousId(),
            );
        });
    }

    public static function onLogin(mixed $component = null): void
    {
        self::safe(function (): void {
            $customer = null;
            try {
                $customer = \Igniter\User\Facades\Auth::customer();
            } catch (Throwable) {
            }

            if (! $customer) {
                return;
            }

            $userId = (string) $customer->getKey();
            Analytics::identify($userId, Identity::traitsFromCustomer($customer));
            Analytics::client()->track(
                Events::ACCOUNT_LOGGED_IN,
                EventContext::merge([]),
                $userId,
                Identity::anonymousId(),
            );
        });
    }

    public static function onLogout(mixed $customer = null): void
    {
        self::safe(function () use ($customer): void {
            $userId = null;
            if (is_object($customer) && method_exists($customer, 'getKey') && $customer->getKey()) {
                $userId = (string) $customer->getKey();
            }

            Analytics::client()->track(
                Events::ACCOUNT_LOGGED_OUT,
                EventContext::merge([]),
                $userId,
                Identity::anonymousId(),
            );
        });
    }

    public static function onCartAdded(mixed $cart, mixed $cartItem = null): void
    {
        self::safe(function () use ($cart, $cartItem): void {
            // Allow Checkout Started again after the customer re-engages the cart
            // (covers sessions burned by earlier empty-cart /checkout bounces).
            session()->forget('cdp_checkout_started');
            Analytics::track(Events::PRODUCT_ADDED, self::cartItemProperties($cartItem ?? $cart));
        });
    }

    public static function onCartUpdated(mixed $cart, mixed $cartItem = null): void
    {
        self::safe(function () use ($cart, $cartItem): void {
            $item = $cartItem ?? $cart;
            $props = self::cartItemProperties($item);
            $qty = (int) ($props['quantity'] ?? 0);
            if ($qty <= 0) {
                Analytics::track(Events::PRODUCT_REMOVED, $props);

                return;
            }
            Analytics::track(Events::PRODUCT_ADDED, $props);
        });
    }

    public static function onCartRemoved(mixed $cart, mixed $cartItem = null): void
    {
        self::safe(function () use ($cart, $cartItem): void {
            Analytics::track(Events::PRODUCT_REMOVED, self::cartItemProperties($cartItem ?? $cart));
        });
    }

    public static function onCartCleared(mixed $cart = null): void
    {
        self::safe(function (): void {
            Analytics::track(Events::CART_CLEARED, []);
        });
    }

    public static function onLocationSelected(mixed $location = null, mixed ...$rest): void
    {
        self::safe(function () use ($location): void {
            $props = [];
            if (is_object($location) && method_exists($location, 'getKey')) {
                $props['location_id'] = (string) $location->getKey();
                $props['location_name'] = (string) ($location->location_name ?? '');
            }
            Analytics::track(Events::LOCATION_SELECTED, array_filter($props));
        });
    }

    public static function onOrderTypeSelected(mixed $location = null, mixed $orderType = null, mixed $previous = null): void
    {
        self::safe(function () use ($orderType, $previous): void {
            Analytics::track(Events::ORDER_TYPE_SELECTED, array_filter([
                'order_type' => $orderType !== null ? (string) $orderType : null,
                'previous_order_type' => $previous !== null ? (string) $previous : null,
            ]));
        });
    }

    public static function onDeliveryAreaSelected(mixed $location = null, mixed $area = null): void
    {
        self::safe(function () use ($area): void {
            $props = [];
            if (is_object($area)) {
                $props['area_id'] = isset($area->area_id) ? (string) $area->area_id : (method_exists($area, 'getKey') ? (string) $area->getKey() : null);
                $props['area_name'] = (string) ($area->name ?? $area->area_name ?? '');
            }
            Analytics::track(Events::DELIVERY_AREA_SELECTED, array_filter($props));
        });
    }

    public static function onTimeslotSelected(mixed $location = null): void
    {
        self::safe(function (): void {
            Analytics::track(Events::TIMESLOT_SELECTED, []);
        });
    }

    public static function onCheckoutStarted(mixed $order = null, mixed $data = null): void
    {
        self::safe(function () use ($order): void {
            if (session('cdp_checkout_started')) {
                return;
            }

            $props = ['producer' => 'backend'];
            if (is_object($order) && method_exists($order, 'getKey') && $order->getKey()) {
                $props['order_id'] = (string) $order->getKey();
                $props['order_type'] = (string) ($order->order_type ?? '');
                $props['total'] = (float) ($order->order_total ?? 0);
            }

            Analytics::track(Events::CHECKOUT_STARTED, array_filter($props, static fn ($v) => $v !== null && $v !== ''));
            session(['cdp_checkout_started' => true]);
        });
    }

    public static function onPaymentStarted(mixed $order = null, mixed $data = null): void
    {
        self::safe(function () use ($order, $data): void {
            $props = [];
            if (is_object($order) && method_exists($order, 'getKey')) {
                $props['order_id'] = (string) $order->getKey();
                $props['total'] = (float) ($order->order_total ?? 0);
                $props['order_type'] = (string) ($order->order_type ?? '');
            }
            if (is_array($data)) {
                $props['payment_code'] = (string) ($data['payment'] ?? $data['payment_code'] ?? '');
            }
            Analytics::track(Events::PAYMENT_STARTED, array_filter($props, static fn ($v) => $v !== null && $v !== ''));
        });
    }

    public static function onOrderCompleted(Order $order): void
    {
        self::safe(function () use ($order): void {
            $userId = $order->customer_id ? (string) $order->customer_id : Identity::userId();
            $products = [];

            try {
                foreach ($order->getOrderMenus() as $menu) {
                    $products[] = [
                        'product_id' => (string) ($menu->menu_id ?? $menu->order_menu_id ?? ''),
                        'sku' => (string) ($menu->menu_id ?? ''),
                        'name' => (string) ($menu->name ?? ''),
                        'price' => (float) ($menu->price ?? 0),
                        'quantity' => (int) ($menu->quantity ?? 1),
                    ];
                }
            } catch (Throwable) {
            }

            $total = (float) ($order->order_total ?? 0);
            $coupon = '';
            try {
                $coupon = (string) ($order->coupon_code ?? '');
            } catch (Throwable) {
            }

            $properties = EventContext::merge([
                'order_id' => (string) $order->getKey(),
                'order_number' => (string) ($order->order_id ?? $order->getKey()),
                'total' => $total,
                'revenue' => $total,
                'currency' => strtoupper((string) ($order->currency_code ?? EventContext::baseProperties()['currency'] ?? 'USD')),
                'order_type' => (string) ($order->order_type ?? ''),
                'coupon' => $coupon,
                'products' => $products,
            ]);

            Analytics::client()->track(
                Events::ORDER_COMPLETED,
                array_filter($properties, static fn ($v) => $v !== null && $v !== ''),
                $userId,
                Identity::anonymousId(),
            );
        });
    }

    public static function onOrderStatusUpdated(mixed $order = null, mixed $statusHistory = null): void
    {
        self::safe(function () use ($order, $statusHistory): void {
            $props = [];
            if (is_object($order) && method_exists($order, 'getKey')) {
                $props['order_id'] = (string) $order->getKey();
                $props['order_type'] = (string) ($order->order_type ?? '');
            }
            if (is_object($statusHistory)) {
                $props['status_id'] = isset($statusHistory->status_id) ? (string) $statusHistory->status_id : null;
                $props['status_name'] = (string) ($statusHistory->status_name ?? $statusHistory->status?->status_name ?? '');
                $props['previous_status_id'] = isset($statusHistory->previous_status_id)
                    ? (string) $statusHistory->previous_status_id
                    : null;
            }
            Analytics::track(Events::ORDER_STATUS_UPDATED, array_filter($props, static fn ($v) => $v !== null && $v !== ''));
        });
    }

    public static function onOrderCancelled(mixed $order = null): void
    {
        self::safe(function () use ($order): void {
            $props = [];
            if (is_object($order) && method_exists($order, 'getKey')) {
                $props['order_id'] = (string) $order->getKey();
                $props['total'] = (float) ($order->order_total ?? 0);
                $props['order_type'] = (string) ($order->order_type ?? '');
            }
            Analytics::track(Events::ORDER_CANCELLED, array_filter($props, static fn ($v) => $v !== null && $v !== ''));
        });
    }

    public static function onOrderRefunded(mixed $order = null): void
    {
        self::safe(function () use ($order): void {
            $props = [];
            if (is_object($order) && method_exists($order, 'getKey')) {
                $props['order_id'] = (string) $order->getKey();
                $props['total'] = (float) ($order->order_total ?? 0);
            }
            Analytics::track(Events::ORDER_REFUNDED, array_filter($props, static fn ($v) => $v !== null && $v !== ''));
        });
    }

    public static function onCouponEntered(mixed $code = null): void
    {
        self::safe(function () use ($code): void {
            $coupon = $code !== null ? trim((string) $code) : '';
            if ($coupon === '') {
                return;
            }

            // Mark an apply attempt so condition.loaded can emit Applied/Denied.
            // The coupon cart condition also loads on every cart rebuild with no code.
            session(['dilibori.cdp.pending_coupon' => $coupon]);

            Analytics::track(Events::COUPON_ENTERED, ['coupon' => $coupon]);
        });
    }

    public static function onCartConditionLoaded(mixed $cart = null, mixed $condition = null): void
    {
        self::safe(function () use ($condition): void {
            if (! self::isCouponCondition($condition)) {
                return;
            }

            // Only react to an apply attempt (beforeApplyCoupon). Routine cart
            // rebuilds load an empty coupon condition that isValid()=false and
            // must not look like Coupon Denied.
            $pending = session()->pull('dilibori.cdp.pending_coupon');
            if (! is_string($pending) || $pending === '') {
                return;
            }

            $stillOnCondition = self::couponCodeFromCondition($condition);
            $code = $stillOnCondition ?: $pending;
            $valid = true;
            try {
                if (is_object($condition) && method_exists($condition, 'isValid')) {
                    $valid = (bool) $condition->isValid();
                }
            } catch (Throwable) {
                $valid = false;
            }

            // onLoad() strips invalid codes before condition.loaded fires.
            if ($valid && $stillOnCondition) {
                Analytics::track(Events::COUPON_APPLIED, ['coupon' => $code]);

                return;
            }

            Analytics::track(Events::COUPON_DENIED, [
                'coupon' => $code,
                'reason' => 'invalid',
            ]);
        });
    }

    public static function onCartConditionRemoved(mixed $cart = null, mixed $condition = null): void
    {
        self::safe(function () use ($condition): void {
            if (! self::isCouponCondition($condition)) {
                return;
            }
            Analytics::track(Events::COUPON_REMOVED, array_filter([
                'coupon' => self::couponCodeFromCondition($condition),
            ]));
        });
    }

    public static function onCouponRedeemed(mixed $history = null): void
    {
        self::safe(function () use ($history): void {
            $props = [];
            if (is_object($history)) {
                $props['coupon'] = (string) ($history->code ?? $history->coupon_code ?? '');
                $props['order_id'] = isset($history->order_id) ? (string) $history->order_id : null;
            }
            Analytics::track(Events::COUPON_REDEEMED, array_filter($props, static fn ($v) => $v !== null && $v !== ''));
        });
    }

    public static function onReservationBooked(mixed $reservation): void
    {
        self::safe(function () use ($reservation): void {
            $props = [
                'reservation_id' => is_object($reservation) && method_exists($reservation, 'getKey')
                    ? (string) $reservation->getKey()
                    : null,
            ];

            if (is_object($reservation)) {
                foreach (['guest_num', 'reserve_date', 'reserve_time', 'location_id'] as $field) {
                    if (isset($reservation->{$field})) {
                        $key = $field === 'guest_num' ? 'party_size' : $field;
                        $props[$key] = is_object($reservation->{$field})
                            ? (string) $reservation->{$field}
                            : $reservation->{$field};
                    }
                }
            }

            Analytics::track(Events::RESERVATION_BOOKED, array_filter($props, static fn ($v) => $v !== null && $v !== ''));
        });
    }

    public static function onReservationStatusUpdated(mixed $reservation = null, mixed $statusHistory = null): void
    {
        self::safe(function () use ($reservation, $statusHistory): void {
            $props = [];
            if (is_object($reservation) && method_exists($reservation, 'getKey')) {
                $props['reservation_id'] = (string) $reservation->getKey();
            }
            if (is_object($statusHistory)) {
                $props['status_id'] = isset($statusHistory->status_id) ? (string) $statusHistory->status_id : null;
                $props['status_name'] = (string) ($statusHistory->status_name ?? $statusHistory->status?->status_name ?? '');
            }
            Analytics::track(Events::RESERVATION_STATUS_UPDATED, array_filter($props, static fn ($v) => $v !== null && $v !== ''));
        });
    }

    public static function onReservationSlotDenied(mixed $date = null, mixed $guestNum = null): void
    {
        self::safe(function () use ($date, $guestNum): void {
            Analytics::track(Events::RESERVATION_SLOT_DENIED, array_filter([
                'reserve_date' => $date !== null ? (string) $date : null,
                'party_size' => $guestNum !== null ? (int) $guestNum : null,
            ]));
        });
    }

    public static function onReviewSubmitted(mixed $review = null): void
    {
        self::safe(function () use ($review): void {
            if (! is_object($review)) {
                return;
            }
            $props = [
                'review_id' => method_exists($review, 'getKey') ? (string) $review->getKey() : null,
                'rating' => isset($review->quality) || isset($review->rating)
                    ? (float) ($review->quality ?? $review->rating ?? 0)
                    : null,
                'order_id' => isset($review->order_id) ? (string) $review->order_id : null,
                'reservation_id' => isset($review->reservation_id) ? (string) $review->reservation_id : null,
                'location_id' => isset($review->location_id) ? (string) $review->location_id : null,
            ];
            // Quality/delivery/service scores if present
            foreach (['quality', 'delivery', 'service'] as $field) {
                if (isset($review->{$field})) {
                    $props[$field] = (float) $review->{$field};
                }
            }
            Analytics::track(Events::REVIEW_SUBMITTED, array_filter($props, static fn ($v) => $v !== null && $v !== ''));
        });
    }

    public static function onNewsletterSubscribed(mixed $subscribe = null, mixed $data = null): void
    {
        self::safe(function () use ($subscribe): void {
            $props = [];
            if (is_object($subscribe)) {
                $props['email'] = (string) ($subscribe->email ?? '');
                if (property_exists($subscribe, 'wasRecentlyCreated') || isset($subscribe->wasRecentlyCreated)) {
                    $props['is_new'] = (bool) $subscribe->wasRecentlyCreated;
                }
            }
            Analytics::track(Events::NEWSLETTER_SUBSCRIBED, array_filter(
                $props,
                static fn ($v) => $v !== null && $v !== '',
            ));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function cartItemProperties(mixed $item): array
    {
        if (! is_object($item)) {
            return [];
        }

        $id = $item->id ?? $item->menu_id ?? $item->rowId ?? null;
        $name = $item->name ?? '';
        $price = $item->price ?? $item->subtotal ?? 0;
        $qty = $item->qty ?? $item->quantity ?? 1;

        return array_filter([
            'product_id' => $id !== null ? (string) $id : null,
            'sku' => $id !== null ? (string) $id : null,
            'name' => (string) $name,
            'price' => (float) $price,
            'quantity' => (int) $qty,
            'cart_id' => isset($item->rowId) ? (string) $item->rowId : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    private static function isCouponCondition(mixed $condition): bool
    {
        if (! is_object($condition)) {
            return false;
        }
        $name = strtolower((string) ($condition->name ?? $condition->getName() ?? ''));

        return $name === 'coupon' || str_contains($name, 'coupon');
    }

    private static function couponCodeFromCondition(mixed $condition): ?string
    {
        if (! is_object($condition)) {
            return null;
        }
        try {
            if (method_exists($condition, 'getMetaData')) {
                $meta = $condition->getMetaData();
                if (is_array($meta) && ! empty($meta['code'])) {
                    return (string) $meta['code'];
                }
            }
        } catch (Throwable) {
        }

        return isset($condition->code) ? (string) $condition->code : null;
    }

    private static function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Never break storefront flows for analytics.
        }
    }
}
