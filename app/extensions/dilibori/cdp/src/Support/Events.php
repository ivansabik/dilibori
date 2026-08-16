<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Support;

/**
 * Canonical track event names (object-action, Proper Case, past tense).
 * Keep in sync with EVENTS.md.
 */
final class Events
{
    // Account
    public const ACCOUNT_CREATED = 'Account Created';

    public const ACCOUNT_LOGGED_IN = 'Account Logged In';

    public const ACCOUNT_LOGGED_OUT = 'Account Logged Out';

    // Browse / cart
    public const PRODUCT_LIST_VIEWED = 'Product List Viewed';

    public const PRODUCT_ADDED = 'Product Added';

    public const PRODUCT_REMOVED = 'Product Removed';

    public const CART_VIEWED = 'Cart Viewed';

    public const CART_CLEARED = 'Cart Cleared';

    // Location / fulfillment
    public const LOCATION_SELECTED = 'Location Selected';

    public const ORDER_TYPE_SELECTED = 'Order Type Selected';

    public const DELIVERY_AREA_SELECTED = 'Delivery Area Selected';

    public const TIMESLOT_SELECTED = 'Timeslot Selected';

    // Checkout / payment / order
    public const CHECKOUT_STARTED = 'Checkout Started';

    public const PAYMENT_STARTED = 'Payment Started';

    public const ORDER_COMPLETED = 'Order Completed';

    public const ORDER_STATUS_UPDATED = 'Order Status Updated';

    public const ORDER_CANCELLED = 'Order Cancelled';

    public const ORDER_REFUNDED = 'Order Refunded';

    // Coupons
    public const COUPON_ENTERED = 'Coupon Entered';

    public const COUPON_APPLIED = 'Coupon Applied';

    public const COUPON_DENIED = 'Coupon Denied';

    public const COUPON_REMOVED = 'Coupon Removed';

    public const COUPON_REDEEMED = 'Coupon Redeemed';

    // Reservations / reviews / marketing
    public const RESERVATION_BOOKED = 'Reservation Booked';

    public const RESERVATION_STATUS_UPDATED = 'Reservation Status Updated';

    public const RESERVATION_SLOT_DENIED = 'Reservation Slot Denied';

    public const REVIEW_SUBMITTED = 'Review Submitted';

    public const NEWSLETTER_SUBSCRIBED = 'Newsletter Subscribed';

    // Errors
    public const APPLICATION_ERROR = 'Application Error';
}
