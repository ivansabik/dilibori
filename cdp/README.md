# Dilibori Customer Data Platform (CDP)

Local RudderStack data plane for the Dilibori storefront. No RudderStack Cloud account. Sources and destinations are in [`workspaceConfig.json`](workspaceConfig.json).

The PHP extension that emits events is [app/extensions/dilibori/cdp](../app/extensions/dilibori/cdp/README.md). Storefront Compose and seed are in [app/README.md](../app/README.md). Repo overview is in the [root README](../README.md).

## Quick start

```sh
make up
```

| Service | URL |
|---------|-----|
| Data plane | http://127.0.0.1:8080 |
| Health | http://127.0.0.1:8080/health |
| Event inspector | http://127.0.0.1:8090 |
| Jobs DB (Postgres) | localhost:6432 |

From the repo root: `make cdp-up`.

## Test a track event

### Curl (data plane only)

```sh
curl -u dilibori_http_write_key_local: -X POST http://127.0.0.1:8080/v1/track \
  -H 'Content-Type: application/json' \
  -d '{
    "anonymousId": "local-test",
    "event": "Product Added",
    "properties": { "product_id": "taco-1", "name": "Suadero", "price": 3.5 }
  }'
```

Open http://127.0.0.1:8090. The webhook destination lists the event within a few seconds.

### From the app (`app/`)

Start CDP first (`make up` here), then the storefront:

```sh
cd ../app
make up
make seed   # if you need demo data / customer login
```

1. Open the inspector: http://127.0.0.1:8090
2. Browse the store: http://127.0.0.1:8001/el-taconazo-alameda-ave/menus

   Expect **page** + **Product List Viewed** (JS)
3. Add a taco to the cart

   Expect **Product Added** (PHP)
4. Log in as `juanagallo@gmail.com` / `customer123!`

   Expect **identify** + **Account Logged In** (PHP); later events should share the same `userId`
5. Go through checkout and place an order (cash / COD is fine on the demo)

   Expect **Checkout Started** then **Order Completed** (PHP)

Event names, hooks, and properties are in the [event catalog](#event-catalog) below.

Storefront failures (out of stock, Livewire 500s, JS errors) emit Application Error. See [Application Error](#application-error).

If the app was already running before CDP came up, recreate it so it joins the `dilibori` network: `cd ../app && docker compose up -d --force-recreate app`.

## Write keys

Match [`workspaceConfig.json`](workspaceConfig.json) and the app env:

| Source | Write key | Used by |
|--------|-----------|---------|
| Javascript | `dilibori_js_write_key_local` | Browser SDK |
| HTTP | `dilibori_http_write_key_local` | TastyIgniter PHP |

## Architecture

- `rudder-server`: gateway (file-based config)
- `rudder-transformer`: destination transforms
- `jobsdb`: Postgres job queue
- `event-sink`: local webhook inspector for development. Point RudderStack at a warehouse when you need one.

App and CDP share the external Docker network `dilibori` so PHP can POST to `http://rudder-server:8080`.

## Make targets

```sh
make help
make up / make down
make health / make logs / make ps
make clean   # wipe jobsdb + inspector events
```

## Event catalog

Source of truth for storefront analytics. Canonical names are Proper Case, object-action, past tense. Properties are `snake_case`. Keep [`Events.php`](../app/extensions/dilibori/cdp/src/Support/Events.php) in sync with this catalog.

**Shared context** (merged into almost every `track` via `EventContext`): `location_id`, `location_name`, `currency`, `locale`, `order_type` (when known).

**Request context** (Segment `context` on the wire): backend events add `userAgent`, `userAgentData` (Client Hints when present), `device.type`, `os.name`, `ip`, `locale`, and `page` from the HTTP request. Browser events get the same from the RudderStack JS SDK (`screen`, `timezone`, etc.).

**Identity:** `identify` + cookie `dilibori_anonymous_id` stitch anonymous and logged-in activity. Backend reads the cookie; JS SDK writes it.

**Producers:** one place per event (no dual-write of `Order Completed` from the browser).

---

### Identity (not track)

#### identify

| | |
|--|--|
| **Producer** | Backend on register/login; frontend when the customer is already logged in |
| **Hook** | `igniter.user.register` / `igniter.user.login` / snippet bootstrap |
| **Traits** | `email`, `name`, `first_name`, `last_name`, `locale` |
| **Question** | Who is this person across sessions? |

#### page (Page Viewed)

| | |
|--|--|
| **Producer** | Frontend |
| **Hook** | After SDK ready, every storefront HTML page |
| **Properties** | `path`, `url`, `referrer` (+ shared context) |
| **Question** | What pages did they see before converting or dropping off? |

---

### Account

#### Account Created

| | |
|--|--|
| **Constant** | `Events::ACCOUNT_CREATED` |
| **Producer** | Backend |
| **Hook** | `igniter.user.register` |
| **Properties** | shared context (after `identify`) |
| **Question** | Acquisition. New customer accounts |

#### Account Logged In

| | |
|--|--|
| **Constant** | `Events::ACCOUNT_LOGGED_IN` |
| **Producer** | Backend |
| **Hook** | `igniter.user.login` |
| **Properties** | shared context |
| **Question** | Return visits; window for "logged in between T1-T2" audiences |

#### Account Logged Out

| | |
|--|--|
| **Constant** | `Events::ACCOUNT_LOGGED_OUT` |
| **Producer** | Backend |
| **Hook** | `igniter.user.logout` |
| **Properties** | shared context |
| **Question** | Session end |

---

### Browse / cart

#### Product List Viewed

| | |
|--|--|
| **Constant** | `Events::PRODUCT_LIST_VIEWED` |
| **Producer** | Frontend |
| **Hook** | Path matches `/menus` (or menu list DOM) |
| **Properties** | `list_id`, `category` |
| **Question** | Funnel top: who opened a menu |

#### Product Added

| | |
|--|--|
| **Constant** | `Events::PRODUCT_ADDED` |
| **Producer** | Backend |
| **Hook** | `cart.added`, `cart.updated` (qty > 0; basket +/-) |
| **Properties** | `product_id`, `sku`, `name`, `price`, `quantity`, `cart_id` |
| **Question** | Add-to-cart conversion; line item mix |

#### Product Removed

| | |
|--|--|
| **Constant** | `Events::PRODUCT_REMOVED` |
| **Producer** | Backend |
| **Hook** | `cart.removed`, `cart.updated` (qty <= 0) |
| **Properties** | same as Product Added |
| **Question** | Cart churn |

#### Cart Viewed

| | |
|--|--|
| **Constant** | `Events::CART_VIEWED` |
| **Producer** | Frontend |
| **Hook** | Path `/cart` (once per path per session) |
| **Properties** | `path` |
| **Question** | Drop-off: viewed cart but never checkout |

#### Cart Cleared

| | |
|--|--|
| **Constant** | `Events::CART_CLEARED` |
| **Producer** | Backend |
| **Hook** | `cart.cleared` |
| **Properties** | shared context |
| **Question** | Abandoned / reset carts |

---

### Location / fulfillment

#### Location Selected

| | |
|--|--|
| **Constant** | `Events::LOCATION_SELECTED` |
| **Producer** | Backend |
| **Hook** | `location.current.updated` |
| **Properties** | `location_id`, `location_name` (+ currency via context) |
| **Question** | El Paso vs Juarez mix; currency split |

#### Order Type Selected

| | |
|--|--|
| **Constant** | `Events::ORDER_TYPE_SELECTED` |
| **Producer** | Backend |
| **Hook** | `location.orderType.updated` |
| **Properties** | `order_type`, `previous_order_type` |
| **Question** | Delivery vs pickup (a domicilio / para recoger) |

#### Delivery Area Selected

| | |
|--|--|
| **Constant** | `Events::DELIVERY_AREA_SELECTED` |
| **Producer** | Backend |
| **Hook** | `location.area.updated` |
| **Properties** | `area_id`, `area_name` |
| **Question** | Zone coverage / unmatched delivery |

#### Timeslot Selected

| | |
|--|--|
| **Constant** | `Events::TIMESLOT_SELECTED` |
| **Producer** | Backend |
| **Hook** | `location.timeslot.updated` |
| **Properties** | shared context |
| **Question** | Future-order / timeslot demand |

---

### Checkout / payment / order

#### Checkout Started

| | |
|--|--|
| **Constant** | `Events::CHECKOUT_STARTED` |
| **Producer** | Backend (authoritative on checkout page load); frontend fallback |
| **Hook** | Checkout page render; `igniter.checkout.beforeSaveOrder`; JS path `/checkout` |
| **Properties** | `order_id`, `order_type`, `total` when known; frontend may set `producer: frontend` |
| **Question** | Funnel mid; checkout drop-off |

#### Payment Started

| | |
|--|--|
| **Constant** | `Events::PAYMENT_STARTED` |
| **Producer** | Backend |
| **Hook** | `igniter.checkout.beforePayment` |
| **Properties** | `order_id`, `total`, `order_type`, `payment_code` |
| **Question** | Payment step drop-off |

#### Order Completed

| | |
|--|--|
| **Constant** | `Events::ORDER_COMPLETED` |
| **Producer** | Backend only |
| **Hook** | `admin.order.paymentProcessed` |
| **Properties** | `order_id`, `order_number`, `total`, `revenue`, `currency`, `order_type`, `coupon`, `products[]` |
| **Question** | Revenue / conversion |

#### Order Status Updated

| | |
|--|--|
| **Constant** | `Events::ORDER_STATUS_UPDATED` |
| **Producer** | Backend |
| **Hook** | `igniter.cart.orderStatusAdded` |
| **Properties** | `order_id`, `order_type`, `status_id`, `status_name`, `previous_status_id` |
| **Question** | Kitchen / ops SLA |

#### Order Cancelled

| | |
|--|--|
| **Constant** | `Events::ORDER_CANCELLED` |
| **Producer** | Backend |
| **Hook** | `admin.order.canceled` |
| **Properties** | `order_id`, `total`, `order_type` |
| **Question** | Cancellation rate |

#### Order Refunded

| | |
|--|--|
| **Constant** | `Events::ORDER_REFUNDED` |
| **Producer** | Backend |
| **Hook** | `admin.order.refundProcessed` |
| **Properties** | `order_id`, `total` |
| **Question** | Refund rate / ops |

---

### Coupons

#### Coupon Entered

| | |
|--|--|
| **Constant** | `Events::COUPON_ENTERED` |
| **Producer** | Backend |
| **Hook** | `igniter.cart.beforeApplyCoupon` |
| **Properties** | `coupon` |
| **Question** | Promo attempt volume |

#### Coupon Applied

| | |
|--|--|
| **Constant** | `Events::COUPON_APPLIED` |
| **Producer** | Backend |
| **Hook** | `cart.condition.loaded` after `igniter.cart.beforeApplyCoupon` (coupon still present and valid) |
| **Properties** | `coupon` |
| **Question** | Promo success rate |

#### Coupon Denied

| | |
|--|--|
| **Constant** | `Events::COUPON_DENIED` |
| **Producer** | Backend |
| **Hook** | `cart.condition.loaded` after `igniter.cart.beforeApplyCoupon` (code missing or invalid) |
| **Properties** | `coupon`, `reason` |
| **Question** | Bad codes / friction at cart |
| **Notes** | Ignored on normal cart rebuilds. The coupon condition loads empty on every cart page; Applied/Denied only follow a Coupon Entered apply attempt. |

#### Coupon Removed

| | |
|--|--|
| **Constant** | `Events::COUPON_REMOVED` |
| **Producer** | Backend |
| **Hook** | `cart.condition.removed` (coupon) |
| **Properties** | `coupon` |
| **Question** | Promo removed before pay |

#### Coupon Redeemed

| | |
|--|--|
| **Constant** | `Events::COUPON_REDEEMED` |
| **Producer** | Backend |
| **Hook** | `admin.order.couponRedeemed` |
| **Properties** | `coupon`, `order_id` |
| **Question** | Promo ROI on completed orders |

---

### Reservations / reviews / marketing

#### Reservation Booked

| | |
|--|--|
| **Constant** | `Events::RESERVATION_BOOKED` |
| **Producer** | Backend |
| **Hook** | `igniter.reservation.confirmed` |
| **Properties** | `reservation_id`, `party_size`, `reserve_date`, `reserve_time`, `location_id` |
| **Question** | Second conversion path (dine-in) |

#### Reservation Status Updated

| | |
|--|--|
| **Constant** | `Events::RESERVATION_STATUS_UPDATED` |
| **Producer** | Backend |
| **Hook** | `igniter.reservation.statusAdded` |
| **Properties** | `reservation_id`, `status_id`, `status_name` |
| **Question** | No-shows / confirmations |

#### Reservation Slot Denied

| | |
|--|--|
| **Constant** | `Events::RESERVATION_SLOT_DENIED` |
| **Producer** | Backend |
| **Hook** | `igniter.reservation.isFullyBookedOn` |
| **Properties** | `reserve_date`, `party_size` |
| **Question** | Capacity friction |

#### Review Submitted

| | |
|--|--|
| **Constant** | `Events::REVIEW_SUBMITTED` |
| **Producer** | Backend |
| **Hook** | `eloquent.created: Igniter\Local\Models\Review` |
| **Properties** | `review_id`, `rating` / `quality` / `delivery` / `service`, `order_id`, `reservation_id`, `location_id` |
| **Question** | Satisfaction / follow-up |

#### Newsletter Subscribed

| | |
|--|--|
| **Constant** | `Events::NEWSLETTER_SUBSCRIBED` |
| **Producer** | Backend |
| **Hook** | `igniter.frontend.subscribed` (`Subscriber::subscribe` / firstOrCreate) |
| **Properties** | `email`, `is_new` (true when a new row was created) |
| **Question** | Marketing list growth (includes "already subscribed" success toast) |

---

### Errors (storefront only)

#### Application Error

| | |
|--|--|
| **Constant** | `Events::APPLICATION_ERROR` |
| **Producer** | Backend (PHP) and frontend (JS) |
| **Hook** | PHP: Igniter `exception.beforeReport` (all storefront throwables; deduped) plus Laravel `reportable` backup. Skips admin, 404, validation. JS: `error`, `unhandledrejection`, Livewire `request` fail, `fetch` status >= 500 (non-Livewire) |
| **Properties** | `error_id`, `error_source` (`php` or `js`), `error_type`, `error_message`, `error_file`, `error_line`, `status_code`, `path`, `url` |
| **Question** | Who hit failure X (bug or storefront UX)? |

JS dedupes by `error_id` once per browser session (`sessionStorage`). PHP dedupes by `error_id` within the request. CDP send paths do not call Laravel `Log`, so a broken `storage/logs` file still allows Application Error to reach RudderStack.

TastyIgniter UX failures (for example "Horchata is currently out of stock") emit `Application Error` with `error_type` = `Igniter\Flame\Exception\ApplicationException`. Filter on that type, or on `error_message`, to separate those checks from unexpected bugs. A Livewire fail may also emit a JS event (`error_source=js`, `error_type=livewire`) for the same click.

#### Audience example: email everyone who hit bug X

Events do not send email. They attach `userId` / `anonymousId` (and `identify` traits such as `email`) so a warehouse or CRM can:

1. Filter `Application Error` where `error_id = '<fingerprint>'`
2. Join to `Account Logged In` (or `identify`) between times T1-T2 if needed
3. Export distinct `email` / `userId` for outreach

Fingerprint: PHP bugs = hash of exception class + file + line; PHP `ApplicationException` / `AjaxException` also include the message; JS = hash of message + source + line.

---

### Debugging storefront exceptions

Igniter throws `Igniter\Flame\Exception\ApplicationException` for many customer-facing checks (stock, closed location, invalid coupon). With `APP_DEBUG=true` Laravel shows a full error page. That page means the check failed. It does not mean CDP or Livewire is broken.

#### 1. Confirm it is UX, not a bug

| Signal | Meaning |
|--------|---------|
| Exception class `ApplicationException` | Expected business rule |
| Message like "... is currently out of stock" | Stock check failed |
| Livewire call `cart-box:add-item` + `menuId` | Which menu item was added |
| Query log includes `from stocks where stockable_id = N` | Stock lookup for that item |
| Inspector `Application Error` with that `error_type` / message | CDP captured the failure |

#### 2. Map `menuId` → name + stock

From `app/`:

```sh
docker compose exec -T db mariadb -utastyigniter -ptastyigniter tastyigniter -e "
SELECT m.menu_id, m.menu_name, s.id AS stock_id, s.location_id,
       s.quantity, s.is_tracked, s.low_stock_threshold
FROM menus m
LEFT JOIN stocks s
  ON s.stockable_type = 'menus' AND s.stockable_id = m.menu_id
WHERE m.menu_id = 9;
"
```

Tracked with `quantity` NULL or `<= 0` → out of stock (`Stock::outOfStock()` / `checkStock()`).

#### 3. Common local cause: seeded quantity never stuck

`Igniter\Cart\Models\Stock` guards `quantity`. Mass assignment (`Stock::create(['quantity' => 50, ...])`) drops it. Seed must call `updateStock($qty, Stock::STATE_RECOUNT)` after create (see `app/seed/el-taconazo.php`).

Quick fix without full re-seed (example: set all tracked rows to 50):

```sh
docker compose exec -T db mariadb -utastyigniter -ptastyigniter tastyigniter -e "
UPDATE stocks SET quantity = 50 WHERE is_tracked = 1 AND (quantity IS NULL OR quantity <= 0);
"
```

Or re-seed from `app/`: `make seed`.

#### 4. Admin UI

In admin, open Restaurant, then Inventory. You can also open the menu item and check its stock. Confirm Track stock and the quantity for that location.

#### 5. CDP / Livewire red herrings

| Symptom | Check |
|---------|--------|
| Exception page on add-to-cart | Stock / mealtime / location rules first |
| No `Product Added` in inspector | Expected when add fails before cart update |
| `Application Error` on OOS / closed | Expected; filter `error_type` / `error_message` |
| No `Application Error` on OOS | Restart app so extension copy updates (`docker compose up -d --force-recreate app`); confirm `CDP_ENABLED` |

---

### Index

| Event | Producer | Constant |
|-------|----------|----------|
| identify | both | - |
| page | frontend | - |
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
