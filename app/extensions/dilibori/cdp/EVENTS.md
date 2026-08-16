# Dilibori CDP — event catalog

Source of truth for storefront analytics. Canonical names are Proper Case, object-action, past tense. Properties are `snake_case`. Keep [`src/Support/Events.php`](src/Support/Events.php) in sync with this file.

**Shared context** (merged into almost every `track` via `EventContext`): `location_id`, `location_name`, `currency`, `locale`, `order_type` (when known).

**Identity:** `identify` + cookie `dilibori_anonymous_id` stitch anonymous and logged-in activity. Backend reads the cookie; JS SDK writes it.

**Producers:** one place per event (no dual-write of `Order Completed` from the browser).

---

## Identity (not track)

### identify

| | |
|--|--|
| **Producer** | Backend on register/login; frontend when the customer is already logged in |
| **Hook** | `igniter.user.register` / `igniter.user.login` / snippet bootstrap |
| **Traits** | `email`, `name`, `first_name`, `last_name`, `locale` |
| **Question** | Who is this person across sessions? |

### page (Page Viewed)

| | |
|--|--|
| **Producer** | Frontend |
| **Hook** | After SDK ready — every storefront HTML page |
| **Properties** | `path`, `url`, `referrer` (+ shared context) |
| **Question** | What pages did they see before converting or dropping off? |

---

## Account

### Account Created

| | |
|--|--|
| **Constant** | `Events::ACCOUNT_CREATED` |
| **Producer** | Backend |
| **Hook** | `igniter.user.register` |
| **Properties** | shared context (after `identify`) |
| **Question** | Acquisition — new customer accounts |

### Account Logged In

| | |
|--|--|
| **Constant** | `Events::ACCOUNT_LOGGED_IN` |
| **Producer** | Backend |
| **Hook** | `igniter.user.login` |
| **Properties** | shared context |
| **Question** | Return visits; window for “logged in between T1–T2” audiences |

### Account Logged Out

| | |
|--|--|
| **Constant** | `Events::ACCOUNT_LOGGED_OUT` |
| **Producer** | Backend |
| **Hook** | `igniter.user.logout` |
| **Properties** | shared context |
| **Question** | Session end |

---

## Browse / cart

### Product List Viewed

| | |
|--|--|
| **Constant** | `Events::PRODUCT_LIST_VIEWED` |
| **Producer** | Frontend |
| **Hook** | Path matches `/menus` (or menu list DOM) |
| **Properties** | `list_id`, `category` |
| **Question** | Funnel top — who opened a menu |

### Product Added

| | |
|--|--|
| **Constant** | `Events::PRODUCT_ADDED` |
| **Producer** | Backend |
| **Hook** | `cart.added`, `cart.updated` (qty > 0; basket +/-) |
| **Properties** | `product_id`, `sku`, `name`, `price`, `quantity`, `cart_id` |
| **Question** | Add-to-cart conversion; line item mix |

### Product Removed

| | |
|--|--|
| **Constant** | `Events::PRODUCT_REMOVED` |
| **Producer** | Backend |
| **Hook** | `cart.removed`, `cart.updated` (qty <= 0) |
| **Properties** | same as Product Added |
| **Question** | Cart churn |

### Cart Viewed

| | |
|--|--|
| **Constant** | `Events::CART_VIEWED` |
| **Producer** | Frontend |
| **Hook** | Path `/cart` (once per path per session) |
| **Properties** | `path` |
| **Question** | Drop-off: viewed cart but never checkout |

### Cart Cleared

| | |
|--|--|
| **Constant** | `Events::CART_CLEARED` |
| **Producer** | Backend |
| **Hook** | `cart.cleared` |
| **Properties** | shared context |
| **Question** | Abandoned / reset carts |

---

## Location / fulfillment

### Location Selected

| | |
|--|--|
| **Constant** | `Events::LOCATION_SELECTED` |
| **Producer** | Backend |
| **Hook** | `location.current.updated` |
| **Properties** | `location_id`, `location_name` (+ currency via context) |
| **Question** | El Paso vs Juarez mix; currency split |

### Order Type Selected

| | |
|--|--|
| **Constant** | `Events::ORDER_TYPE_SELECTED` |
| **Producer** | Backend |
| **Hook** | `location.orderType.updated` |
| **Properties** | `order_type`, `previous_order_type` |
| **Question** | Delivery vs pickup (a domicilio / para recoger) |

### Delivery Area Selected

| | |
|--|--|
| **Constant** | `Events::DELIVERY_AREA_SELECTED` |
| **Producer** | Backend |
| **Hook** | `location.area.updated` |
| **Properties** | `area_id`, `area_name` |
| **Question** | Zone coverage / unmatched delivery |

### Timeslot Selected

| | |
|--|--|
| **Constant** | `Events::TIMESLOT_SELECTED` |
| **Producer** | Backend |
| **Hook** | `location.timeslot.updated` |
| **Properties** | shared context |
| **Question** | Future-order / timeslot demand |

---

## Checkout / payment / order

### Checkout Started

| | |
|--|--|
| **Constant** | `Events::CHECKOUT_STARTED` |
| **Producer** | Backend (authoritative); frontend fallback on `/checkout` once per session |
| **Hook** | `igniter.checkout.beforeSaveOrder`; JS path `/checkout` |
| **Properties** | `order_id`, `order_type`, `total` when known; frontend may set `producer: frontend` |
| **Question** | Funnel mid; checkout drop-off |

### Payment Started

| | |
|--|--|
| **Constant** | `Events::PAYMENT_STARTED` |
| **Producer** | Backend |
| **Hook** | `igniter.checkout.beforePayment` |
| **Properties** | `order_id`, `total`, `order_type`, `payment_code` |
| **Question** | Payment step drop-off |

### Order Completed

| | |
|--|--|
| **Constant** | `Events::ORDER_COMPLETED` |
| **Producer** | Backend only |
| **Hook** | `admin.order.paymentProcessed` |
| **Properties** | `order_id`, `order_number`, `total`, `revenue`, `currency`, `order_type`, `coupon`, `products[]` |
| **Question** | Revenue / conversion |

### Order Status Updated

| | |
|--|--|
| **Constant** | `Events::ORDER_STATUS_UPDATED` |
| **Producer** | Backend |
| **Hook** | `igniter.cart.orderStatusAdded` |
| **Properties** | `order_id`, `order_type`, `status_id`, `status_name`, `previous_status_id` |
| **Question** | Kitchen / ops SLA |

### Order Cancelled

| | |
|--|--|
| **Constant** | `Events::ORDER_CANCELLED` |
| **Producer** | Backend |
| **Hook** | `admin.order.canceled` |
| **Properties** | `order_id`, `total`, `order_type` |
| **Question** | Cancellation rate |

### Order Refunded

| | |
|--|--|
| **Constant** | `Events::ORDER_REFUNDED` |
| **Producer** | Backend |
| **Hook** | `admin.order.refundProcessed` |
| **Properties** | `order_id`, `total` |
| **Question** | Refund rate / ops |

---

## Coupons

### Coupon Entered

| | |
|--|--|
| **Constant** | `Events::COUPON_ENTERED` |
| **Producer** | Backend |
| **Hook** | `igniter.cart.beforeApplyCoupon` |
| **Properties** | `coupon` |
| **Question** | Promo attempt volume |

### Coupon Applied

| | |
|--|--|
| **Constant** | `Events::COUPON_APPLIED` |
| **Producer** | Backend |
| **Hook** | `cart.condition.loaded` (coupon condition valid) |
| **Properties** | `coupon` |
| **Question** | Promo success rate |

### Coupon Denied

| | |
|--|--|
| **Constant** | `Events::COUPON_DENIED` |
| **Producer** | Backend |
| **Hook** | `cart.condition.loaded` (coupon condition invalid) |
| **Properties** | `coupon`, `reason` |
| **Question** | Bad codes / friction at cart |

### Coupon Removed

| | |
|--|--|
| **Constant** | `Events::COUPON_REMOVED` |
| **Producer** | Backend |
| **Hook** | `cart.condition.removed` (coupon) |
| **Properties** | `coupon` |
| **Question** | Promo removed before pay |

### Coupon Redeemed

| | |
|--|--|
| **Constant** | `Events::COUPON_REDEEMED` |
| **Producer** | Backend |
| **Hook** | `admin.order.couponRedeemed` |
| **Properties** | `coupon`, `order_id` |
| **Question** | Promo ROI on completed orders |

---

## Reservations / reviews / marketing

### Reservation Booked

| | |
|--|--|
| **Constant** | `Events::RESERVATION_BOOKED` |
| **Producer** | Backend |
| **Hook** | `igniter.reservation.confirmed` |
| **Properties** | `reservation_id`, `party_size`, `reserve_date`, `reserve_time`, `location_id` |
| **Question** | Second conversion path (dine-in) |

### Reservation Status Updated

| | |
|--|--|
| **Constant** | `Events::RESERVATION_STATUS_UPDATED` |
| **Producer** | Backend |
| **Hook** | `igniter.reservation.statusAdded` |
| **Properties** | `reservation_id`, `status_id`, `status_name` |
| **Question** | No-shows / confirmations |

### Reservation Slot Denied

| | |
|--|--|
| **Constant** | `Events::RESERVATION_SLOT_DENIED` |
| **Producer** | Backend |
| **Hook** | `igniter.reservation.isFullyBookedOn` |
| **Properties** | `reserve_date`, `party_size` |
| **Question** | Capacity friction |

### Review Submitted

| | |
|--|--|
| **Constant** | `Events::REVIEW_SUBMITTED` |
| **Producer** | Backend |
| **Hook** | `eloquent.created: Igniter\Local\Models\Review` |
| **Properties** | `review_id`, `rating` / `quality` / `delivery` / `service`, `order_id`, `reservation_id`, `location_id` |
| **Question** | Satisfaction / follow-up |

### Newsletter Subscribed

| | |
|--|--|
| **Constant** | `Events::NEWSLETTER_SUBSCRIBED` |
| **Producer** | Backend |
| **Hook** | `eloquent.created: Igniter\Frontend\Models\Subscribe` |
| **Properties** | `email` |
| **Question** | Marketing list growth |

---

## Errors (storefront only)

### Application Error

| | |
|--|--|
| **Constant** | `Events::APPLICATION_ERROR` |
| **Producer** | Backend (PHP) and frontend (JS) |
| **Hook** | Laravel `ExceptionHandler::reportable` (skip admin, 404, validation); JS `error`, `unhandledrejection`, `fetch` status >= 500 |
| **Properties** | `error_id`, `error_source` (`php` or `js`), `error_type`, `error_message`, `error_file`, `error_line`, `status_code`, `path`, `url` |
| **Question** | Who hit bug X? (see below) |

JS dedupes by `error_id` once per browser session (`sessionStorage`).

### Audience example: email everyone who hit bug X

Events do **not** send email. They attach `userId` / `anonymousId` (and `identify` traits like `email`) so a warehouse or CRM can:

1. Filter `Application Error` where `error_id = '<fingerprint>'`
2. Join to `Account Logged In` (or `identify`) between times T1–T2 if needed
3. Export distinct `email` / `userId` for outreach

Fingerprint: PHP = hash of exception class + file + line; JS = hash of message + source + line.

---

## Index

| Event | Producer | Constant |
|-------|----------|----------|
| identify | both | — |
| page | frontend | — |
| Account Created | backend | `ACCOUNT_CREATED` |
| Account Logged In | backend | `ACCOUNT_LOGGED_IN` |
| Account Logged Out | backend | `ACCOUNT_LOGGED_OUT` |
| Product List Viewed | frontend | `PRODUCT_LIST_VIEWED` |
| Product Added | backend | `PRODUCT_ADDED` |
| Product Removed | backend | `PRODUCT_REMOVED` |
| Cart Viewed | frontend | `CART_VIEWED` |
| Cart Cleared | backend | `CART_CLEARED` |
| Location Selected | backend | `LOCATION_SELECTED` |
| Order Type Selected | backend | `ORDER_TYPE_SELECTED` |
| Delivery Area Selected | backend | `DELIVERY_AREA_SELECTED` |
| Timeslot Selected | backend | `TIMESLOT_SELECTED` |
| Checkout Started | backend (+ JS fallback) | `CHECKOUT_STARTED` |
| Payment Started | backend | `PAYMENT_STARTED` |
| Order Completed | backend | `ORDER_COMPLETED` |
| Order Status Updated | backend | `ORDER_STATUS_UPDATED` |
| Order Cancelled | backend | `ORDER_CANCELLED` |
| Order Refunded | backend | `ORDER_REFUNDED` |
| Coupon Entered | backend | `COUPON_ENTERED` |
| Coupon Applied | backend | `COUPON_APPLIED` |
| Coupon Denied | backend | `COUPON_DENIED` |
| Coupon Removed | backend | `COUPON_REMOVED` |
| Coupon Redeemed | backend | `COUPON_REDEEMED` |
| Reservation Booked | backend | `RESERVATION_BOOKED` |
| Reservation Status Updated | backend | `RESERVATION_STATUS_UPDATED` |
| Reservation Slot Denied | backend | `RESERVATION_SLOT_DENIED` |
| Review Submitted | backend | `REVIEW_SUBMITTED` |
| Newsletter Subscribed | backend | `NEWSLETTER_SUBSCRIBED` |
| Application Error | both | `APPLICATION_ERROR` |
