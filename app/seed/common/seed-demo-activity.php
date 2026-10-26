<?php

/**
 * Shared demo activity: customers, coupons, orders, reservations, reviews,
 * subscribers. Reads the site menu from the DB and a per-locale persona pack.
 *
 * Required when included from seed-from-manifest.php:
 *   $manifest (array), $mode (string), $locationIds (int[]), $manifestPath (string)
 *
 * Only runs when demo.enabled and SEED_MODE=initial.
 */

declare(strict_types=1);

use Igniter\Cart\Models\Menu;
use Igniter\Cart\Models\Order;
use Igniter\Coupons\Models\Coupon;
use Igniter\Coupons\Models\CouponHistory;
use Igniter\Local\Models\Location;
use Igniter\Reservation\Models\DiningArea;
use Igniter\Reservation\Models\DiningSection;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;
use Igniter\User\Models\Address;
use Igniter\User\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (!isset($manifest) || !is_array($manifest)) {
    throw new RuntimeException('seed-demo-activity.php requires $manifest from seed-from-manifest.php');
}

$demo = $manifest['demo'] ?? null;
if (!is_array($demo) || empty($demo['enabled'])) {
    say('Demo activity skipped (demo.enabled false or missing)');

    return;
}

$seedMode = $mode ?? (getenv('SEED_MODE') ?: 'initial');
if ($seedMode !== 'initial') {
    say('Demo activity skipped (SEED_MODE='.$seedMode.')');

    return;
}

$personaCode = (string) ($demo['persona'] ?? '');
$personaPath = __DIR__.'/demo/personas/'.$personaCode.'.json';
if ($personaCode === '' || !is_file($personaPath)) {
    throw new RuntimeException("Missing persona pack: {$personaPath}");
}
$persona = json_decode((string) file_get_contents($personaPath), true, 512, JSON_THROW_ON_ERROR);

$historyDays = max(1, (int) ($demo['history_days'] ?? 60));
$ordersPerDay = $demo['orders_per_day'] ?? [2, 6];
$ordersPerDayMin = max(0, (int) ($ordersPerDay[0] ?? 2));
$ordersPerDayMax = max($ordersPerDayMin, (int) ($ordersPerDay[1] ?? 6));
$customerCount = max(1, (int) ($demo['customers'] ?? 40));
$featured = is_array($demo['featured_customer'] ?? null) ? $demo['featured_customer'] : null;
$customerPassword = (string) ($persona['customer_password'] ?? 'customer123!');
$slug = (string) $manifest['slug'];

mt_srand(crc32($slug));
say("Demo activity → persona={$personaCode} history={$historyDays}d customers={$customerCount}");

$locations = Location::query()
    ->where('location_status', 1)
    ->when(
        !empty($locationIds),
        fn ($q) => $q->whereIn('location_id', $locationIds),
    )
    ->orderByDesc('is_default')
    ->orderBy('location_id')
    ->get();
if ($locations->isEmpty()) {
    throw new RuntimeException('Demo activity: no active locations');
}

$locationBySlug = [];
$locationCurrencyById = [];
foreach ($manifest['locations'] as $locDef) {
    $locationCurrencyById[(string) $locDef['slug']] = (string) ($locDef['currency'] ?? $manifest['default_currency'] ?? 'USD');
}
foreach ($locations as $location) {
    $slugKey = (string) $location->permalink_slug;
    $locationBySlug[$slugKey] = $location;
    if (!isset($locationCurrencyById[$slugKey])) {
        $locationCurrencyById[$slugKey] = ((int) $location->location_country_id === 138) ? 'MXN' : 'USD';
    }
}

// ---------------------------------------------------------------------------
// Wipe demo-owned tables (initial only)
// ---------------------------------------------------------------------------
DB::statement('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'order_menu_options',
    'order_menus',
    'order_totals',
    'orders',
    'status_history',
    'reservation_tables',
    'reservations',
    'igniter_reviews',
    'igniter_coupon_categories',
    'igniter_coupon_customer_groups',
    'igniter_coupon_customers',
    'igniter_coupon_menus',
    'igniter_coupons_history',
    'igniter_coupons',
    'igniter_frontend_subscribers',
] as $table) {
    if (Schema::hasTable($table)) {
        DB::table($table)->truncate();
    }
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$demoEmails = [];
if ($featured && !empty($featured['email'])) {
    $demoEmails[] = strtolower((string) $featured['email']);
}
$guestEmail = strtolower((string) ($persona['guest']['email'] ?? 'guest.checkout@demo.example'));
$demoEmails[] = $guestEmail;

$existingDemoCustomers = Customer::query()
    ->where(function ($q) use ($demoEmails) {
        $q->where('email', 'like', '%@demo.example');
        if ($demoEmails !== []) {
            $q->orWhereIn('email', $demoEmails);
        }
    })
    ->get();
foreach ($existingDemoCustomers as $oldCustomer) {
    Address::query()->where('customer_id', $oldCustomer->getKey())->delete();
    $oldCustomer->delete();
}
say('Wiped prior demo orders/reservations/coupons/customers');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
$pick = function (array $items) {
    if ($items === []) {
        return null;
    }

    return $items[array_rand($items)];
};

$menuName = function (Menu $menu): string {
    $name = $menu->menu_name;
    if (is_array($name)) {
        return (string) ($name['en'] ?? $name['es_MX'] ?? reset($name) ?: 'Menu');
    }

    return (string) $name;
};

$loadMenusForLocation = function (Location $location) use ($menuName): array {
    $menus = Menu::query()
        ->where('menu_status', 1)
        ->whereHas('locations', fn ($q) => $q->where('locations.location_id', $location->getKey()))
        ->with(['menu_options.menu_option_values.option_value', 'menu_options.option'])
        ->orderBy('menu_id')
        ->get();

    $catalog = [];
    foreach ($menus as $menu) {
        $options = [];
        foreach ($menu->menu_options as $menuOption) {
            $values = [];
            foreach ($menuOption->menu_option_values as $value) {
                $values[] = [
                    'menu_option_value_id' => (int) $value->getKey(),
                    'name' => (string) ($value->name ?? 'Option'),
                    'price' => (float) ($value->price ?? 0),
                ];
            }
            if ($values === []) {
                continue;
            }
            $options[] = [
                'menu_option_id' => (int) $menuOption->getKey(),
                'required' => (bool) $menuOption->is_required,
                'display_type' => (string) ($menuOption->display_type ?? 'radio'),
                'max_selected' => (int) ($menuOption->max_selected ?? 0),
                'values' => $values,
            ];
        }
        $catalog[] = [
            'menu' => $menu,
            'name' => $menuName($menu),
            'base_price' => (float) $menu->menu_price,
            'options' => $options,
        ];
    }

    return $catalog;
};

$buildLine = function (array $item, int $qty, bool $preferOptions) use ($pick): array {
    $base = (float) $item['base_price'];
    $optionRows = [];
    $optionPrice = 0.0;

    foreach ($item['options'] as $option) {
        $selected = [];
        $isRadio = in_array($option['display_type'], ['radio', 'select'], true);
        if ($option['required'] || ($preferOptions && $isRadio)) {
            $value = $pick($option['values']);
            if ($value !== null) {
                $selected[] = $value;
            }
        } elseif ($preferOptions && $option['display_type'] === 'checkbox' && mt_rand(0, 1) === 1) {
            $value = $pick($option['values']);
            if ($value !== null) {
                $selected[] = $value;
            }
        }

        foreach ($selected as $value) {
            $optionPrice += (float) $value['price'];
            $optionRows[] = [
                'menu_option_id' => $option['menu_option_id'],
                'menu_option_value_id' => $value['menu_option_value_id'],
                'order_option_name' => $value['name'],
                'order_option_price' => (float) $value['price'],
                'quantity' => $qty,
                'free_qty' => 0,
            ];
        }
    }

    $unit = round($base + $optionPrice, 2);

    return [
        'menu' => $item['menu'],
        'name' => $item['name'],
        'qty' => $qty,
        'price' => $unit,
        'subtotal' => round($unit * $qty, 2),
        'options' => $optionRows,
    ];
};

$randomBasket = function (array $catalog, bool $preferOptions = false, int $minLines = 1, int $maxLines = 4) use ($pick, $buildLine): array {
    if ($catalog === []) {
        return [];
    }
    $count = mt_rand($minLines, min($maxLines, count($catalog)));
    $keys = array_rand($catalog, $count);
    if (!is_array($keys)) {
        $keys = [$keys];
    }
    $lines = [];
    foreach ($keys as $key) {
        $qty = mt_rand(1, 3);
        $lines[] = $buildLine($catalog[$key], $qty, $preferOptions);
    }

    return $lines;
};

$deliveryFeeFor = function (Location $location) use ($locationCurrencyById): float {
    $currency = $locationCurrencyById[(string) $location->permalink_slug] ?? 'USD';

    return $currency === 'MXN' ? 45.0 : 2.99;
};

$totalsLabels = str_starts_with((string) ($demo['persona'] ?? ''), 'es')
    ? ['subtotal' => 'Subtotal', 'delivery' => 'Envío', 'total' => 'Total', 'coupon' => 'Cupón']
    : ['subtotal' => 'Sub Total', 'delivery' => 'Delivery', 'total' => 'Order Total', 'coupon' => 'Coupon'];

$seedOrder = function (
    ?Customer $customer,
    Location $location,
    string $type,
    int $statusId,
    Carbon $when,
    string $time,
    bool $asap,
    string $comment,
    array $lines,
    float $deliveryFee = 0,
    ?Coupon $coupon = null,
    ?array $guest = null,
) use ($totalsLabels, $slug): Order {
    $first = $customer?->first_name ?? ($guest['first_name'] ?? 'Guest');
    $last = $customer?->last_name ?? ($guest['last_name'] ?? 'Checkout');
    $email = $customer?->email ?? ($guest['email'] ?? 'guest@demo.example');
    $phone = $customer?->telephone ?? ($guest['telephone'] ?? '');

    $order = new Order;
    $order->forceFill([
        'customer_id' => $customer?->getKey(),
        'first_name' => $first,
        'last_name' => $last,
        'email' => $email,
        'telephone' => $phone,
        'location_id' => $location->getKey(),
        'address_id' => $type === 'delivery' ? ($customer?->address_id) : null,
        'comment' => $comment,
        'payment' => 'cod',
        'order_type' => $type,
        'order_date' => $when->toDateString(),
        'order_time' => $time,
        'order_time_is_asap' => $asap ? 1 : 0,
        'status_id' => $statusId,
        'processed' => $statusId === 9 ? 0 : 1,
        'hash' => md5(uniqid($slug, true)),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'dilibori demo seeder',
        'cart' => [],
        'total_items' => 0,
        'order_total' => 0,
        'created_at' => $when,
        'updated_at' => $when,
        'status_updated_at' => $when,
    ]);
    $order->saveQuietly();

    foreach ($lines as $line) {
        /** @var Menu $menu */
        $menu = $line['menu'];
        $orderMenu = $order->menus()->create([
            'menu_id' => $menu->getKey(),
            'name' => $line['name'],
            'quantity' => $line['qty'],
            'price' => $line['price'],
            'subtotal' => $line['subtotal'],
            'comment' => '',
            'option_values' => [],
        ]);
        foreach ($line['options'] as $opt) {
            $order->menu_options()->create([
                'order_menu_id' => $orderMenu->getKey(),
                'menu_option_id' => $opt['menu_option_id'],
                'menu_option_value_id' => $opt['menu_option_value_id'],
                'order_option_name' => $opt['order_option_name'],
                'order_option_price' => $opt['order_option_price'],
                'quantity' => $opt['quantity'],
                'free_qty' => $opt['free_qty'],
            ]);
        }
    }

    $subtotal = (float) $order->menus()->sum('subtotal');
    $order->totals()->create([
        'code' => 'subtotal',
        'title' => $totalsLabels['subtotal'],
        'value' => $subtotal,
        'priority' => 0,
        'is_summable' => 0,
    ]);

    $couponAmount = 0.0;
    if ($coupon && $subtotal >= (float) $coupon->min_total) {
        if ($coupon->type === 'P') {
            $couponAmount = round($subtotal * ((float) $coupon->discount / 100), 2);
        } else {
            $couponAmount = min((float) $coupon->discount, $subtotal + $deliveryFee);
        }
        if (($coupon->apply_coupon_on ?? 'whole_cart') === 'delivery_fee') {
            $couponAmount = min($couponAmount, $deliveryFee);
        }
        $couponAmount = round($couponAmount, 2);
        if ($couponAmount > 0) {
            $order->totals()->create([
                'code' => 'coupon',
                'title' => $totalsLabels['coupon'].' ['.$coupon->code.']',
                'value' => -$couponAmount,
                'priority' => 1,
                'is_summable' => 1,
            ]);
            if (class_exists(CouponHistory::class)) {
                CouponHistory::query()->create([
                    'coupon_id' => $coupon->getKey(),
                    'order_id' => $order->getKey(),
                    'customer_id' => $customer?->getKey(),
                    'code' => $coupon->code,
                    'min_total' => $coupon->min_total,
                    'amount' => $couponAmount,
                    'status' => 1,
                    'created_at' => $when,
                    'updated_at' => $when,
                ]);
            }
        }
    }

    if ($deliveryFee > 0) {
        $order->totals()->create([
            'code' => 'delivery',
            'title' => $totalsLabels['delivery'],
            'value' => $deliveryFee,
            'priority' => 2,
            'is_summable' => 1,
        ]);
    }

    $order->totals()->create([
        'code' => 'total',
        'title' => $totalsLabels['total'],
        'value' => max(0, $subtotal + $deliveryFee - $couponAmount),
        'priority' => 99,
        'is_summable' => 0,
    ]);
    $order->calculateTotals();

    $statusRow = DB::table('statuses')->where('status_id', $statusId)->first();
    DB::table('status_history')->insert([
        'object_id' => $order->getKey(),
        'object_type' => 'orders',
        'user_id' => null,
        'status_id' => $statusId,
        'notify' => 0,
        'comment' => $statusRow->status_comment ?? '',
        'created_at' => $when,
        'updated_at' => $when,
    ]);

    return $order->fresh() ?? $order;
};

$ensureDining = function (Location $location): ?DiningTable {
    if (!class_exists(DiningArea::class)) {
        return null;
    }

    $area = DiningArea::query()->where('location_id', $location->getKey())->first();
    if (!$area) {
        $area = DiningArea::query()->create([
            'location_id' => $location->getKey(),
            'name' => $location->location_name.' Main Floor',
            'description' => 'Demo dining area',
        ]);
        say("Dining area created → {$location->permalink_slug}");
    }

    $section = DiningSection::query()->firstOrCreate(
        ['location_id' => $location->getKey(), 'name' => 'Main'],
        [
            'description' => 'Main seating',
            'priority' => 1,
            'is_enabled' => 1,
            'color' => '#7BC8A4',
        ],
    );

    $tables = DiningTable::query()
        ->where('dining_area_id', $area->getKey())
        ->where('is_enabled', 1)
        ->orderBy('id')
        ->get();

    if ($tables->isEmpty()) {
        for ($i = 1; $i <= 6; $i++) {
            $table = new DiningTable;
            $table->forceFill([
                'dining_area_id' => $area->getKey(),
                'dining_section_id' => $section->getKey(),
                'name' => 'T'.$i,
                'shape' => 'rectangle',
                'min_capacity' => 2,
                'max_capacity' => $i <= 2 ? 2 : ($i <= 4 ? 4 : 6),
                'extra_capacity' => 0,
                'priority' => $i,
                'is_combo' => 0,
                'is_enabled' => 1,
            ]);
            $table->save();
        }
        $tables = DiningTable::query()
            ->where('dining_area_id', $area->getKey())
            ->where('is_enabled', 1)
            ->orderBy('id')
            ->get();
        say("Dining tables created → {$location->permalink_slug} ({$tables->count()})");
    }

    return $tables->first();
};

$seedReservation = function (
    Customer $customer,
    Location $location,
    int $statusId,
    Carbon $when,
    string $time,
    int $guests,
    string $comment,
    ?DiningTable $table,
): void {
    if (!class_exists(Reservation::class)) {
        return;
    }
    $reservation = new Reservation;
    $reservation->location_id = $location->getKey();
    $reservation->customer_id = $customer->getKey();
    $reservation->first_name = $customer->first_name;
    $reservation->last_name = $customer->last_name;
    $reservation->email = $customer->email;
    $reservation->telephone = $customer->telephone;
    $reservation->reserve_date = $when->toDateString();
    $reservation->reserve_time = $time;
    $reservation->guest_num = $guests;
    $reservation->occasion_id = null;
    $reservation->comment = $comment;
    $reservation->status_id = $statusId;
    $reservation->created_at = $when->copy()->subDays(1);
    $reservation->updated_at = $when->copy()->subDays(1);
    $reservation->save();
    if ($table && method_exists($reservation, 'tables')) {
        $reservation->tables()->sync([$table->getKey()]);
    }
};

// ---------------------------------------------------------------------------
// Coupons
// ---------------------------------------------------------------------------
$couponsByCode = [];
$couponLocationIds = []; // code => int[]
$couponDefs = is_array($demo['coupons'] ?? null) ? $demo['coupons'] : [];
foreach ($couponDefs as $couponData) {
    if (!is_array($couponData)) {
        continue;
    }
    $slugs = $couponData['locations'] ?? [];
    $ids = [];
    foreach ($slugs as $locSlug) {
        if (isset($locationBySlug[$locSlug])) {
            $ids[] = (int) $locationBySlug[$locSlug]->getKey();
        }
    }
    if ($ids === []) {
        say('Coupon skipped (no locations): '.($couponData['code'] ?? '?'));
        continue;
    }

    $payload = [
        'name' => (string) ($couponData['name'] ?? $couponData['code']),
        'code' => (string) $couponData['code'],
        'type' => (string) $couponData['type'],
        'discount' => (float) $couponData['discount'],
        'min_total' => (float) $couponData['min_total'],
        'description' => (string) ($couponData['description'] ?? ''),
        'customer_redemptions' => (int) ($couponData['customer_redemptions'] ?? 0),
        'validity' => (string) ($couponData['validity'] ?? 'forever'),
        'apply_coupon_on' => (string) ($couponData['apply_coupon_on'] ?? 'whole_cart'),
        'status' => 1,
        'redemptions' => 0,
        'min_menu_quantity' => 0,
        'auto_apply' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ];
    if (($payload['validity'] === 'recurring') && !empty($couponData['recurring_every'])) {
        $payload['recurring_every'] = $couponData['recurring_every'];
        $payload['recurring_from_time'] = $couponData['recurring_from_time'] ?? '11:00:00';
        $payload['recurring_to_time'] = $couponData['recurring_to_time'] ?? '22:00:00';
    }

    $coupon = Coupon::create($payload);
    $coupon->locations()->sync($ids);
    $couponsByCode[$coupon->code] = $coupon;
    $couponLocationIds[$coupon->code] = $ids;
    say("Coupon: {$coupon->code}");
}

// ---------------------------------------------------------------------------
// Customers
// ---------------------------------------------------------------------------
$customers = [];
$firstNames = $persona['first_names'] ?? ['Alex'];
$lastNames = $persona['last_names'] ?? ['Demo'];
$streets = $persona['street_names'] ?? ['Main St'];

if ($featured) {
    $defaultLoc = $locations->first();
    $customer = Customer::query()->updateOrCreate(
        ['email' => (string) $featured['email']],
        [
            'first_name' => (string) $featured['first_name'],
            'last_name' => (string) $featured['last_name'],
            'password' => $customerPassword,
            'telephone' => (string) ($featured['telephone'] ?? '555-0100'),
            'customer_group_id' => 1,
            'newsletter' => 1,
            'status' => 1,
            'is_activated' => 1,
            'activated_at' => now(),
            'last_location_area' => '',
        ],
    );
    $address = Address::query()->create([
        'customer_id' => $customer->getKey(),
        'address_1' => '100 '.$pick($streets),
        'city' => (string) $defaultLoc->location_city,
        'state' => (string) $defaultLoc->location_state,
        'postcode' => (string) $defaultLoc->location_postcode,
        'country_id' => (int) $defaultLoc->location_country_id,
    ]);
    $customer->address_id = $address->getKey();
    $customer->save();
    $customers[] = $customer;
}

for ($i = count($customers); $i < $customerCount; $i++) {
    $loc = $locations[$i % $locations->count()];
    $first = $pick($firstNames);
    $last = $pick($lastNames);
    $email = sprintf('customer%03d@demo.example', $i + 1);
    $phoneBase = ((int) $loc->location_country_id === 138) ? '+52 656 555 %04d' : '(915) 555-%04d';
    $customer = Customer::query()->updateOrCreate(
        ['email' => $email],
        [
            'first_name' => $first,
            'last_name' => $last,
            'password' => $customerPassword,
            'telephone' => sprintf($phoneBase, 2000 + $i),
            'customer_group_id' => 1,
            'newsletter' => 1,
            'status' => 1,
            'is_activated' => 1,
            'activated_at' => now()->subDays(mt_rand(1, $historyDays)),
            'last_location_area' => '',
        ],
    );
    Address::query()->where('customer_id', $customer->getKey())->delete();
    $address = Address::query()->create([
        'customer_id' => $customer->getKey(),
        'address_1' => (100 + $i).' '.$pick($streets),
        'city' => (string) $loc->location_city,
        'state' => (string) $loc->location_state,
        'postcode' => (string) $loc->location_postcode,
        'country_id' => (int) $loc->location_country_id,
    ]);
    $customer->address_id = $address->getKey();
    $customer->save();
    $customers[] = $customer;
}
say('Customers: '.count($customers)." (password {$customerPassword})");

// ---------------------------------------------------------------------------
// Per-location curated + history
// ---------------------------------------------------------------------------
$orderComments = $persona['order_comments'] ?? ['Thanks'];
$reservationComments = $persona['reservation_comments'] ?? ['Table please'];
$reviewBuckets = $persona['reviews'] ?? [];
$guest = $persona['guest'] ?? [
    'first_name' => 'Guest',
    'last_name' => 'Checkout',
    'email' => 'guest.checkout@demo.example',
    'telephone' => '555-0199',
];

$totalOrders = 0;
$totalReservations = 0;
$totalReviews = 0;

foreach ($locations as $location) {
    $catalog = $loadMenusForLocation($location);
    if ($catalog === []) {
        say("Demo skipped for {$location->permalink_slug}: no menus");
        continue;
    }

    $fee = $deliveryFeeFor($location);
    $table = $ensureDining($location);
    $locId = (int) $location->getKey();
    $locCoupons = [];
    foreach ($couponsByCode as $code => $coupon) {
        if (in_array($locId, $couponLocationIds[$code] ?? [], true)) {
            $locCoupons[] = $coupon;
        }
    }
    $primaryCoupon = $locCoupons[0] ?? null;

    $featuredCustomer = $customers[0];
    $c1 = $customers[min(1, count($customers) - 1)];
    $c2 = $customers[min(2, count($customers) - 1)];

    // Curated scenarios (one of each status / type / edge case)
    $curated = [
        ['customer' => $featuredCustomer, 'type' => 'delivery', 'status' => 5, 'when' => now()->subDays(2)->setTime(19, 10), 'time' => '19:15:00', 'asap' => false, 'comment' => $orderComments[0] ?? '', 'fee' => $fee, 'options' => true, 'coupon' => null, 'guest' => null, 'lines' => 3],
        ['customer' => $c1, 'type' => 'collection', 'status' => 3, 'when' => now()->subHours(3)->setTime(12, 5), 'time' => '12:20:00', 'asap' => true, 'comment' => $orderComments[1] ?? '', 'fee' => 0, 'options' => true, 'coupon' => null, 'guest' => null, 'lines' => 3],
        ['customer' => $c2, 'type' => 'delivery', 'status' => 1, 'when' => now()->subMinutes(40), 'time' => '18:45:00', 'asap' => true, 'comment' => $orderComments[2] ?? '', 'fee' => $fee, 'options' => false, 'coupon' => null, 'guest' => null, 'lines' => 2],
        ['customer' => $featuredCustomer, 'type' => 'collection', 'status' => 9, 'when' => now()->subDays(6)->setTime(8, 30), 'time' => '08:45:00', 'asap' => false, 'comment' => $orderComments[3] ?? 'Canceled', 'fee' => 0, 'options' => false, 'coupon' => null, 'guest' => null, 'lines' => 1],
        ['customer' => $c1, 'type' => 'delivery', 'status' => 2, 'when' => now()->subHours(1), 'time' => '17:30:00', 'asap' => true, 'comment' => $orderComments[4] ?? '', 'fee' => $fee, 'options' => false, 'coupon' => null, 'guest' => null, 'lines' => 2],
        ['customer' => $c2, 'type' => 'collection', 'status' => 4, 'when' => now()->subMinutes(90), 'time' => '13:00:00', 'asap' => false, 'comment' => $orderComments[5] ?? '', 'fee' => 0, 'options' => true, 'coupon' => null, 'guest' => null, 'lines' => 2],
        ['customer' => null, 'type' => 'collection', 'status' => 5, 'when' => now()->subDays(1)->setTime(20, 0), 'time' => '20:15:00', 'asap' => false, 'comment' => $orderComments[6] ?? 'Guest checkout', 'fee' => 0, 'options' => false, 'coupon' => null, 'guest' => $guest, 'lines' => 2],
        ['customer' => $featuredCustomer, 'type' => 'delivery', 'status' => 5, 'when' => now()->subDays(4)->setTime(18, 0), 'time' => '18:30:00', 'asap' => false, 'comment' => $orderComments[7] ?? 'Coupon order', 'fee' => $fee, 'options' => true, 'coupon' => $primaryCoupon, 'guest' => null, 'lines' => 3],
        ['customer' => $c1, 'type' => 'delivery', 'status' => 5, 'when' => now()->subDays(3)->setTime(19, 45), 'time' => '20:00:00', 'asap' => false, 'comment' => $orderComments[8] ?? 'Large party order', 'fee' => $fee, 'options' => true, 'coupon' => null, 'guest' => null, 'lines' => 5],
        ['customer' => $c2, 'type' => 'collection', 'status' => 1, 'when' => now()->addDay()->setTime(11, 0), 'time' => '12:00:00', 'asap' => false, 'comment' => $orderComments[9] ?? 'Scheduled future pickup', 'fee' => 0, 'options' => false, 'coupon' => null, 'guest' => null, 'lines' => 2],
        ['customer' => $featuredCustomer, 'type' => 'delivery', 'status' => 5, 'when' => now()->subDays(8)->setTime(13, 20), 'time' => '13:45:00', 'asap' => false, 'comment' => implode(' ', array_slice($orderComments, 0, 5)), 'fee' => $fee, 'options' => false, 'coupon' => null, 'guest' => null, 'lines' => 2],
    ];

    foreach ($curated as $spec) {
        $maxLines = min((int) $spec['lines'], count($catalog));
        $lines = $randomBasket($catalog, (bool) $spec['options'], max(1, min(2, $maxLines)), $maxLines);
        if ($lines === []) {
            continue;
        }
        $seedOrder(
            $spec['customer'],
            $location,
            $spec['type'],
            $spec['status'],
            $spec['when'],
            $spec['time'],
            $spec['asap'],
            (string) $spec['comment'],
            $lines,
            (float) $spec['fee'],
            $spec['coupon'],
            $spec['guest'],
        );
        $totalOrders++;
    }

    // Reservation curated set
    if ($table) {
        $confirmed = 6;
        $canceled = 7;
        $pending = 8;
        $seedReservation($featuredCustomer, $location, $confirmed, now()->addDays(2), '19:00:00', 4, $reservationComments[0] ?? '', $table);
        $seedReservation($c1, $location, $pending, now()->addDays(5), '20:00:00', 2, $reservationComments[1] ?? '', $table);
        $seedReservation($c2, $location, $canceled, now()->addDays(1), '18:30:00', 3, $reservationComments[2] ?? '', $table);
        $seedReservation($featuredCustomer, $location, $confirmed, now(), '13:00:00', 2, $reservationComments[3] ?? '', $table);
        $seedReservation($c1, $location, $canceled, now()->subDays(3), '19:30:00', 4, $reservationComments[4] ?? 'No-show', $table);
        $seedReservation($c2, $location, $confirmed, now()->addDays(7), '20:30:00', 8, $reservationComments[5] ?? 'Large party', $table);
        $totalReservations += 6;
    }

    // Background history
    for ($day = $historyDays; $day >= 1; $day--) {
        $date = now()->subDays($day);
        $dow = (int) $date->dayOfWeekIso; // 1=Mon … 7=Sun
        $weekendBoost = ($dow >= 5) ? 2 : 0;
        $count = mt_rand($ordersPerDayMin, $ordersPerDayMax) + $weekendBoost;
        for ($n = 0; $n < $count; $n++) {
            $hourPool = [11, 12, 13, 18, 19, 20, 21];
            $hour = $hourPool[array_rand($hourPool)];
            $minute = mt_rand(0, 59);
            $when = $date->copy()->setTime($hour, $minute);
            $customer = $customers[array_rand($customers)];
            $type = mt_rand(0, 1) === 0 ? 'delivery' : 'collection';
            $statusRoll = mt_rand(1, 100);
            $statusId = $statusRoll <= 8 ? 9 : ($statusRoll <= 14 ? 3 : 5);
            $lines = $randomBasket($catalog, mt_rand(0, 1) === 1, 1, 4);
            if ($lines === []) {
                continue;
            }
            $useCoupon = $primaryCoupon && $statusId === 5 && mt_rand(1, 100) <= 12;
            $seedOrder(
                $customer,
                $location,
                $type,
                $statusId,
                $when,
                sprintf('%02d:%02d:00', $hour, min(59, $minute + 15)),
                mt_rand(0, 1) === 1,
                (string) $pick($orderComments),
                $lines,
                $type === 'delivery' ? $fee : 0,
                $useCoupon ? $primaryCoupon : null,
            );
            $totalOrders++;

            // Occasional review on completed orders (DB insert avoids CDP eloquent hooks)
            if ($statusId === 5 && mt_rand(1, 100) <= 18 && Schema::hasTable('igniter_reviews')) {
                $rating = (string) $pick(['5', '5', '5', '4', '4', '3']);
                $texts = $reviewBuckets[$rating] ?? ['Good food.'];
                $reviewedAt = $when->copy()->addHours(2);
                DB::table('igniter_reviews')->insert([
                    'customer_id' => $customer->getKey(),
                    'reviewable_id' => $location->getKey(),
                    'reviewable_type' => 'locations',
                    'author' => trim($customer->first_name.' '.$customer->last_name),
                    'location_id' => $location->getKey(),
                    'quality' => (int) $rating,
                    'delivery' => max(3, (int) $rating - mt_rand(0, 1)),
                    'service' => max(3, (int) $rating - mt_rand(0, 1)),
                    'review_text' => (string) $pick($texts),
                    'review_status' => 1,
                    'created_at' => $reviewedAt,
                    'updated_at' => $reviewedAt,
                ]);
                $totalReviews++;
            }
        }

        // Lower-rate reservations in history
        if ($table && mt_rand(1, 100) <= 35) {
            $customer = $customers[array_rand($customers)];
            $statusId = mt_rand(1, 100) <= 15 ? 7 : 6;
            $seedReservation(
                $customer,
                $location,
                $statusId,
                $date->copy()->setTime(mt_rand(18, 20), [0, 30][mt_rand(0, 1)]),
                sprintf('%02d:%02d:00', mt_rand(18, 20), [0, 30][mt_rand(0, 1)]),
                mt_rand(2, 6),
                (string) $pick($reservationComments),
                $table,
            );
            $totalReservations++;
        }
    }

    say(sprintf(
        'Location %s → curated+history seeded (menus=%d)',
        $location->permalink_slug,
        count($catalog),
    ));
}

// ---------------------------------------------------------------------------
// Newsletter subscribers (DB insert avoids frontend.subscribed CDP hooks)
// ---------------------------------------------------------------------------
if (Schema::hasTable('igniter_frontend_subscribers')) {
    $locals = $persona['subscriber_locals'] ?? ['fan'];
    $domain = $slug.'.example';
    $count = 0;
    foreach ($locals as $local) {
        $email = $local.'@'.$domain;
        $exists = DB::table('igniter_frontend_subscribers')->where('email', $email)->exists();
        if ($exists) {
            continue;
        }
        DB::table('igniter_frontend_subscribers')->insert([
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $count++;
    }
    say("Newsletter subscribers: {$count}");
}

$featuredLogin = $featured
    ? "{$featured['email']} / {$customerPassword}"
    : "customer001@demo.example / {$customerPassword}";

say("Demo activity complete → orders={$totalOrders} reservations={$totalReservations} reviews={$totalReviews}");
say("Customer demo → {$featuredLogin}");
