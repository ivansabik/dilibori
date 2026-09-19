#!/usr/bin/env php
<?php

/**
 * Rich El Taconazo (El Paso, TX) demo seed.
 * Exercises menus, options, specials, mealtimes, allergens, delivery areas,
 * working hours, location order/reservation settings, coupons, customers,
 * addresses, reviews, pages, banners, subscribers, stocks, and dining.
 *
 * Menu/category/page copy is stored per locale (en + es_MX) via ti-ext-translate.
 * Safe to re-run.
 */

declare(strict_types=1);

use Igniter\Cart\Models\Category;
use Igniter\Cart\Models\Ingredient;
use Igniter\Cart\Models\Mealtime;
use Igniter\Cart\Models\Menu;
use Igniter\Cart\Models\MenuOption;
use Igniter\Cart\Models\Order;
use Igniter\Cart\Models\Stock;
use Igniter\Coupons\Models\Coupon;
use Igniter\Frontend\Models\Banner;
use Igniter\Frontend\Models\Slider;
use Igniter\Frontend\Models\Subscriber;
use Igniter\Local\Models\Location;
use Igniter\Local\Models\LocationSettings;
use Igniter\Local\Models\Review;
use Igniter\Local\Models\WorkingHour;
use Igniter\Main\Classes\MediaLibrary;
use Igniter\Main\Models\Theme;
use Igniter\Pages\Models\Page;
use Igniter\System\Facades\Assets;
use Igniter\Reservation\Models\DiningArea;
use Igniter\Reservation\Models\DiningSection;
use Igniter\Reservation\Models\DiningTable;
use Igniter\Reservation\Models\Reservation;
use Igniter\System\Models\Currency;
use Igniter\System\Models\Language;
use Igniter\User\Facades\AdminAuth;
use Igniter\User\Models\Address;
use Igniter\User\Models\Customer;
use Igniter\User\Models\User;
use Igniter\User\Models\UserGroup;
use Igniter\User\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "Seeding rich El Taconazo demo data...\n";

function say(string $msg): void
{
    echo "  {$msg}\n";
}

/**
 * Attach a media thumb and ensure the original lands on the persistent media volume.
 * TI can leave DB rows / placeholder thumbs when the attachment file is missing on disk.
 */
function attachLocationPhoto($model, string $sourcePath, string $tag = 'thumb'): void
{
    if (!is_file($sourcePath)) {
        throw new RuntimeException("Missing photo: {$sourcePath}");
    }

    $model->clearMediaTag($tag);
    $model->newMediaInstance()->addFromFile($sourcePath, $tag);
    $model = $model->fresh() ?? $model;

    $media = $model->getMedia($tag)->first();
    if (!$media) {
        throw new RuntimeException("Media attach failed for tag {$tag}");
    }

    $relative = $media->getDiskPath(); // e.g. media/attachments/public/.../file.png
    $absolute = storage_path('app/public/'.$relative);
    $dir = dirname($absolute);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    // Always overwrite from seed source — TI sometimes records media without
    // leaving the original on the persistent volume (placeholder thumbs only).
    if (!copy($sourcePath, $absolute)) {
        throw new RuntimeException("Failed to copy photo to {$absolute}");
    }
    @chmod($absolute, 0666);

    // Drop stale placeholder thumbs so the next request regenerates from the real file
    foreach (glob(dirname($absolute).'/thumb_'.$media->getKey().'_*') ?: [] as $thumb) {
        @unlink($thumb);
    }
}

function settingSet(string $item, mixed $value, string $sort = 'config'): void
{
    $serialized = is_array($value) || is_object($value) ? 1 : null;
    $stored = $serialized ? serialize($value) : (string)$value;

    $exists = DB::table('settings')->where('item', $item)->where('sort', $sort)->exists();
    if ($exists) {
        DB::table('settings')->where('item', $item)->where('sort', $sort)->update([
            'value' => $stored,
            'serialized' => $serialized,
        ]);
    } else {
        DB::table('settings')->insert([
            'sort' => $sort,
            'item' => $item,
            'value' => $stored,
            'serialized' => $serialized,
        ]);
    }
}

function saveLocationSettings(Location $location, string $item, array $data): void
{
    $settings = LocationSettings::instance($location, $item);
    foreach ($data as $key => $value) {
        $settings->setAttribute($key, $value);
    }
    $settings->save();
}

/** English + Español (México) for ti-ext-translate attributes. */
function t(string $en, string $es): array
{
    return ['en' => $en, 'es_MX' => $es];
}

// ---------------------------------------------------------------------------
// Site-wide settings (USD default; MXN enabled for Cd Juárez location)
// ---------------------------------------------------------------------------
$usd = Currency::query()->where('currency_code', 'USD')->first();
if ($usd) {
    $usd->currency_status = 1;
    $usd->currency_rate = 1;
    $usd->is_default = 1;
    $usd->save();
}
$mxn = Currency::query()->firstOrCreate(
    ['currency_code' => 'MXN'],
    [
        'country_id' => 138,
        'currency_name' => 'Mexican Peso',
        'currency_symbol' => '$',
        'currency_rate' => 1,
        'symbol_position' => 0,
        'thousand_sign' => ',',
        'decimal_sign' => '.',
        'decimal_position' => 2,
        'iso_alpha2' => 'MX',
        'iso_alpha3' => 'MEX',
        'iso_numeric' => 484,
        'currency_status' => 1,
        'is_default' => 0,
    ],
);
$mxn->currency_name = 'Mexican Peso';
$mxn->currency_symbol = '$';
$mxn->currency_rate = 1;
$mxn->currency_status = 1;
$mxn->is_default = 0;
$mxn->country_id = 138;
$mxn->save();

Currency::query()->whereNotIn('currency_code', ['USD', 'MXN'])->update(['currency_status' => 0]);
$usdId = $usd?->currency_id ?: 3;

settingSet('site_name', 'El Taconazo');
settingSet('site_email', 'hola@eltaconazo.example');
settingSet('sender_name', 'El Taconazo Taqueria');
settingSet('sender_email', 'hola@eltaconazo.example');
settingSet('protocol', 'smtp');
settingSet('smtp_host', getenv('MAIL_HOST') ?: 'mailpit');
settingSet('smtp_port', getenv('MAIL_PORT') ?: '1025');
settingSet('smtp_encryption', '');
settingSet('smtp_user', '');
settingSet('smtp_pass', '');
settingSet('timezone', 'America/Denver');
settingSet('currency_id', (string)$usdId);
settingSet('default_currency', (string)$usdId);
settingSet('distance_unit', 'mi');
settingSet('tax_mode', '0');
settingSet('guest_order', '1');
settingSet('allow_registration', '1');
settingSet('default_geocode', ['lat' => 31.7587, 'lng' => -106.4431]);
say("Site settings → America/Denver, USD default + MXN enabled (#{$usdId})");
say("Mail → smtp mailpit:1025, From El Taconazo Taqueria");

// ---------------------------------------------------------------------------
// Languages: English (default) + Spanish (Mexico)
// UI packs from the marketplace need a Carte key. Catalog copy is stored as
// separate en / es_MX translations (ti-ext-translate).
// ---------------------------------------------------------------------------
$english = Language::query()->where('code', 'en')->first();
if ($english) {
    $english->status = 1;
    $english->is_default = 1;
    $english->save();
}

Language::query()->where('code', 'es_ES')->update(['status' => 0, 'is_default' => 0]);

$spanish = Language::query()->firstOrCreate(
    ['code' => 'es_MX'],
    [
        'name' => 'Español (México)',
        'idiom' => 'spanish',
        'status' => 1,
        'can_delete' => 1,
        'is_default' => 0,
    ],
);
$spanish->name = 'Español (México)';
$spanish->idiom = 'spanish';
$spanish->status = 1;
$spanish->is_default = 0;
$spanish->save();

Language::clearInternalCache();
Language::applySupportedLanguages();
config(['localization.supportedLocales' => ['en', 'es_MX']]);
// Picker + session win over Accept-Language (`es` would not match `es_MX`).
settingSet('detect_language', '0');
say('Languages → English (default) + Español México (storefront picker)');

// ---------------------------------------------------------------------------
// Location
// ---------------------------------------------------------------------------
$location = Location::query()->orderBy('location_id')->first() ?? new Location;
$location->fill([
    'location_name' => 'El Taconazo - Alameda Ave',
    'location_email' => 'hola@eltaconazo.example',
    'description' => t(
        'Authentic Mexican taquería in El Paso, Texas. Breakfast (pambazo, torta de chilaquil), trompo al pastor, suadero, cabeza & costilla.',
        'Taquería auténtica en El Paso, Texas. Desayunos (pambazo, torta de chilaquil), trompo al pastor, suadero, cabeza y costilla.',
    ),
    'location_address_1' => '4500 Alameda Ave',
    'location_address_2' => 'Suite B',
    'location_city' => 'El Paso',
    'location_state' => 'TX',
    'location_postcode' => '79905',
    'location_country_id' => 223,
    'location_telephone' => '(915) 555-0142',
    'location_lat' => 31.758700,
    'location_lng' => -106.443100,
    'location_status' => 1,
    'permalink_slug' => 'el-taconazo-alameda-ave',
    'is_default' => 1,
    'is_auto_lat_lng' => 0,
]);
$location->save();
$locationId = (int)$location->getKey();
say("Location #{$locationId}: El Taconazo - Alameda Ave, El Paso, TX");

// Cd Juárez location (same brand, MXN menu)
$locationJuarez = Location::query()
    ->whereIn('permalink_slug', ['el-taconazo-tecnologico', 'el-taconazo-cd-juarez'])
    ->first() ?? new Location;
$locationJuarez->fill([
    'location_name' => 'El Taconazo - Tecnológico',
    'location_email' => 'juarez@eltaconazo.example',
    'description' => t(
        'El Taconazo in Ciudad Juárez, Chihuahua. Same trompo al pastor, suadero, cabeza & costilla — priced in Mexican pesos.',
        'El Taconazo en Ciudad Juárez, Chihuahua. El mismo trompo al pastor, suadero, cabeza y costilla — precios en pesos mexicanos.',
    ),
    'location_address_1' => 'Av. Tecnológico 1545',
    'location_address_2' => '',
    'location_city' => 'Ciudad Juárez',
    'location_state' => 'Chihuahua',
    'location_postcode' => '32310',
    'location_country_id' => 138,
    'location_telephone' => '+52 656 555 0142',
    'location_lat' => 31.690400,
    'location_lng' => -106.424500,
    'location_status' => 1,
    'permalink_slug' => 'el-taconazo-tecnologico',
    'is_default' => 0,
    'is_auto_lat_lng' => 0,
]);
$locationJuarez->save();
$juarezId = (int)$locationJuarez->getKey();
say("Location #{$juarezId}: El Taconazo - Tecnológico, Ciudad Juárez, MX");

$bothLocationIds = [$locationId, $juarezId];

// ---------------------------------------------------------------------------
// Super admin (igniter:install --no-interaction does not create one;
// without this the admin UI stays on Initial Setup)
// ---------------------------------------------------------------------------
$adminEmail = getenv('ADMIN_EMAIL') ?: 'operations@taconazotaqueria.com';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '12345678abc!';
$admin = User::query()->where('email', $adminEmail)->first()
    ?? User::query()->orderBy('user_id')->first();
if (!$admin) {
    $roleId = UserRole::query()->orderBy('user_role_id')->value('user_role_id');
    $groupId = UserGroup::query()->orderBy('user_group_id')->value('user_group_id');
    $languageId = Language::query()->where('code', 'en')->value('language_id')
        ?? Language::query()->orderBy('language_id')->value('language_id');
    if (!$roleId || !$groupId) {
        throw new RuntimeException('Cannot create admin: missing user role or group after install');
    }
    AdminAuth::getProvider()->register([
        'email' => $adminEmail,
        'name' => 'Operations',
        'language_id' => $languageId,
        'user_role_id' => $roleId,
        'username' => 'admin',
        'password' => $adminPassword,
        'super_user' => true,
        'groups' => [$groupId],
        'locations' => $bothLocationIds,
    ], true);
    $admin = User::query()->where('email', $adminEmail)->first();
    say("Admin created → {$adminEmail} / {$adminPassword}");
} else {
    $admin->email = $adminEmail;
    $admin->password = $adminPassword;
    if ($admin->isFillable('status') || array_key_exists('status', $admin->getAttributes())) {
        $admin->status = 1;
    }
    $admin->save();
    if (method_exists($admin, 'locations')) {
        $admin->locations()->sync($bothLocationIds);
    }
    say("Admin login → {$adminEmail} / {$adminPassword}");
}

// Wipe + rebuild catalog-related demo rows
DB::statement('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'menu_item_option_linked_values',
    'menu_item_option_values',
    'menu_item_options',
    'menu_option_values',
    'menu_options',
    'menu_categories',
    'menu_mealtimes',
    'menus_specials',
    'ingredientables',
    'ingredients',
    'menus',
    'categories',
    'stocks',
    'stock_history',
    'location_areas',
    'igniter_coupon_categories',
    'igniter_coupon_customer_groups',
    'igniter_coupon_customers',
    'igniter_coupon_menus',
    'igniter_coupons_history',
    'igniter_coupons',
    'igniter_reviews',
    'igniter_frontend_banners',
    'igniter_frontend_subscribers',
    'reservation_tables',
    'reservations',
    'order_menu_options',
    'order_menus',
    'order_totals',
    'orders',
    'status_history',
    'igniter_translate_attributes',
] as $table) {
    if (Schema::hasTable($table)) {
        DB::table($table)->truncate();
    }
}
// Truncating menus leaves media_attachments rows pointing at reused menu IDs.
if (Schema::hasTable('media_attachments')) {
    DB::table('media_attachments')->where('attachment_type', 'menus')->delete();
}
DB::table('locationables')
    ->whereIn('locationable_type', ['menus', 'categories', 'menu_options', 'mealtimes', 'ingredients', 'coupons'])
    ->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

// Truncate dropped translation rows; reload so the next save is dirty.
$location = $location->fresh() ?? $location;
$location->description = t(
    'Authentic Mexican taquería in El Paso, Texas. Breakfast (pambazo, torta de chilaquil), trompo al pastor, suadero, cabeza & costilla.',
    'Taquería auténtica en El Paso, Texas. Desayunos (pambazo, torta de chilaquil), trompo al pastor, suadero, cabeza y costilla.',
);
$location->save();

$locationJuarez = $locationJuarez->fresh() ?? $locationJuarez;
$locationJuarez->description = t(
    'El Taconazo in Ciudad Juárez, Chihuahua. Same trompo al pastor, suadero, cabeza & costilla — priced in Mexican pesos.',
    'El Taconazo en Ciudad Juárez, Chihuahua. El mismo trompo al pastor, suadero, cabeza y costilla — precios en pesos mexicanos.',
);
$locationJuarez->save();

$storePhoto = '/opt/seed/images/el-taconazo.png';
if (is_file($storePhoto)) {
    attachLocationPhoto($location, $storePhoto, 'thumb');
    say('Store photo → El Paso thumb');

    if (class_exists(Slider::class)) {
        $slider = Slider::query()->where('code', 'home-slider')->first();
        if ($slider) {
            attachLocationPhoto($slider, $storePhoto, 'images');
            say('Store photo → homepage slider');
        }
    }
} else {
    say('El Paso store photo skipped (missing '.$storePhoto.')');
}

$juarezPhoto = '/opt/seed/images/el-taconazo-juarez.png';
if (is_file($juarezPhoto)) {
    attachLocationPhoto($locationJuarez, $juarezPhoto, 'thumb');
    say('Store photo → Cd Juárez thumb');
} else {
    say('Cd Juárez store photo skipped (missing '.$juarezPhoto.')');
}

$logoFile = '/opt/seed/images/el-taconazo-logo.png';
$logoMediaPath = 'el-taconazo-logo.png';
if (is_file($logoFile)) {
    $media = resolve(MediaLibrary::class);
    $media->put($logoMediaPath, file_get_contents($logoFile));
    $media->resetCache();

    if (!is_link(public_path('storage'))) {
        Illuminate\Support\Facades\Artisan::call('storage:link');
    }

    settingSet('site_logo', $logoMediaPath);
    say('Logo → header + favicon');
} else {
    say('Logo skipped (missing '.$logoFile.')');
}

$theme = Theme::query()->where('code', 'igniter-orange')->first();
if ($theme) {
    $data = $theme->data ?? [];
    if (is_file($logoFile)) {
        $data['logo_image'] = $logoMediaPath;
        $data['favicon'] = $logoMediaPath;
        $data['logo_height'] = '72px';
    }
    // Black buttons / primary actions (replaces Orange default #ff4900)
    $data['color-primary'] = '#000000';
    $data['heading-color'] = '#000000';
    // Footer “Follow us on”
    $data['social'] = [
        [
            'class' => 'fab fa-instagram',
            'title' => 'Instagram',
            'url' => 'https://www.instagram.com/eltaconazo/',
        ],
        [
            'class' => 'fab fa-facebook',
            'title' => 'Facebook',
            'url' => 'https://www.facebook.com/eltaconazo/',
        ],
        [
            'class' => 'fab fa-tiktok',
            'title' => 'TikTok',
            'url' => 'https://www.tiktok.com/@eltaconazo',
        ],
    ];
    // GDPR cookie banner (EN defaults; ES via eucookiebanner override + lang pack)
    $data['enable_gdpr'] = true;
    $data['gdpr_cookie_message'] = 'We use own and third party cookies to improve our services. If you continue to browse, consider accepting its use';
    $data['gdpr_accept_text'] = 'OK';
    $data['gdpr_more_info_text'] = 'More Information';
    $theme->data = $data;
    $theme->save();
    Theme::clearThemeInstances();

    try {
        Assets::buildBundles($theme->getTheme());
        say('Theme → black primary + Instagram / Facebook / TikTok');
    } catch (Throwable $e) {
        say('Theme CSS rebuild skipped: '.$e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Working hours (Sun=0 … Sat=6): opening / delivery / collection
// ---------------------------------------------------------------------------
$schedules = [
    // weekday => [open, close] — open early for desayuno
    0 => ['07:00:00', '21:00:00'], // Sun
    1 => ['07:00:00', '22:00:00'], // Mon
    2 => ['07:00:00', '22:00:00'],
    3 => ['07:00:00', '22:00:00'],
    4 => ['07:00:00', '22:00:00'],
    5 => ['07:00:00', '23:30:00'], // Fri
    6 => ['07:00:00', '23:30:00'], // Sat
];
foreach (['opening', 'delivery', 'collection'] as $type) {
    foreach ($schedules as $weekday => [$open, $close]) {
        WorkingHour::query()->updateOrCreate(
            ['location_id' => $locationId, 'type' => $type, 'weekday' => $weekday],
            ['opening_time' => $open, 'closing_time' => $close, 'status' => 1],
        );
        WorkingHour::query()->updateOrCreate(
            ['location_id' => $juarezId, 'type' => $type, 'weekday' => $weekday],
            ['opening_time' => $open, 'closing_time' => $close, 'status' => 1],
        );
    }
}
say('Working hours set (7am–late for desayuno, delivery + pickup) — both locations');

// ---------------------------------------------------------------------------
// Location order / reservation settings
// ---------------------------------------------------------------------------
saveLocationSettings($location, 'delivery', [
    'is_enabled' => 1,
    'add_lead_time' => 1,
    'time_interval' => 15,
    'lead_time' => 35,
    'cancellation_timeout' => 5,
    'min_order_amount' => 15,
    'time_restriction' => 0,
    'future_orders' => ['is_enabled' => 1, 'min_days' => 0, 'days' => 3],
]);
saveLocationSettings($location, 'collection', [
    'is_enabled' => 1,
    'add_lead_time' => 1,
    'time_interval' => 10,
    'lead_time' => 20,
    'cancellation_timeout' => 5,
    'min_order_amount' => 8,
    'time_restriction' => 0,
    'future_orders' => ['is_enabled' => 1, 'min_days' => 0, 'days' => 2],
]);
saveLocationSettings($location, 'checkout', [
    'guest_checkout' => 1,
    'limit_orders' => 0,
]);
saveLocationSettings($location, 'booking', [
    'is_enabled' => 1,
    'auto_allocate_table' => 1,
    'time_interval' => 15,
    'stay_time' => 60,
    'min_guest_count' => 1,
    'max_guest_count' => 12,
    'min_advance_time' => 1,
    'max_advance_time' => 14,
]);
saveLocationSettings($locationJuarez, 'delivery', [
    'is_enabled' => 1,
    'add_lead_time' => 1,
    'time_interval' => 15,
    'lead_time' => 35,
    'cancellation_timeout' => 5,
    'min_order_amount' => 150,
    'time_restriction' => 0,
    'future_orders' => ['is_enabled' => 1, 'min_days' => 0, 'days' => 3],
]);
saveLocationSettings($locationJuarez, 'collection', [
    'is_enabled' => 1,
    'add_lead_time' => 1,
    'time_interval' => 10,
    'lead_time' => 20,
    'cancellation_timeout' => 5,
    'min_order_amount' => 80,
    'time_restriction' => 0,
    'future_orders' => ['is_enabled' => 1, 'min_days' => 0, 'days' => 2],
]);
saveLocationSettings($locationJuarez, 'checkout', [
    'guest_checkout' => 1,
    'limit_orders' => 0,
]);
saveLocationSettings($locationJuarez, 'booking', [
    'is_enabled' => 1,
    'auto_allocate_table' => 1,
    'time_interval' => 15,
    'stay_time' => 60,
    'min_guest_count' => 1,
    'max_guest_count' => 12,
    'min_advance_time' => 1,
    'max_advance_time' => 14,
]);
say('Delivery, pickup, checkout & reservations enabled — both locations');

// ---------------------------------------------------------------------------
// Delivery areas (circle around the taquería)
// location_id / color are not fillable on LocationArea — use the relation + assign color.
// ---------------------------------------------------------------------------
$centralElPaso = $location->delivery_areas()->create([
    'name' => 'Central El Paso',
    'type' => 'circle',
    'is_default' => 1,
    'priority' => 1,
    'boundaries' => [
        'circle' => json_encode([
            'lat' => 31.7587,
            'lng' => -106.4431,
            'radius' => 4,
        ]),
    ],
    'conditions' => [
        ['priority' => 1, 'amount' => 0, 'type' => 'above', 'total' => 30],
        ['priority' => 2, 'amount' => 2.99, 'type' => 'above', 'total' => 15],
        ['priority' => 3, 'amount' => 4.99, 'type' => 'all', 'total' => 0],
    ],
]);
$centralElPaso->color = '#F16745';
$centralElPaso->save();

$greaterElPaso = $location->delivery_areas()->create([
    'name' => 'Greater El Paso',
    'type' => 'circle',
    'is_default' => 0,
    'priority' => 2,
    'boundaries' => [
        'circle' => json_encode([
            'lat' => 31.7619,
            'lng' => -106.4850,
            'radius' => 10,
        ]),
    ],
    'conditions' => [
        ['priority' => 1, 'amount' => 0, 'type' => 'above', 'total' => 45],
        ['priority' => 2, 'amount' => 5.99, 'type' => 'all', 'total' => 0],
    ],
]);
$greaterElPaso->color = '#4CC3D9';
$greaterElPaso->save();

$centroJuarez = $locationJuarez->delivery_areas()->create([
    'name' => 'Centro Juárez',
    'type' => 'circle',
    'is_default' => 1,
    'priority' => 1,
    'boundaries' => [
        'circle' => json_encode([
            'lat' => 31.6904,
            'lng' => -106.4245,
            'radius' => 5,
        ]),
    ],
    'conditions' => [
        ['priority' => 1, 'amount' => 0, 'type' => 'above', 'total' => 350],
        ['priority' => 2, 'amount' => 35, 'type' => 'above', 'total' => 180],
        ['priority' => 3, 'amount' => 55, 'type' => 'all', 'total' => 0],
    ],
]);
$centroJuarez->color = '#F16745';
$centroJuarez->save();

$zonaPronaf = $locationJuarez->delivery_areas()->create([
    'name' => 'Zona Pronaf / Tecnológico',
    'type' => 'circle',
    'is_default' => 0,
    'priority' => 2,
    'boundaries' => [
        'circle' => json_encode([
            'lat' => 31.6904,
            'lng' => -106.4245,
            'radius' => 10,
        ]),
    ],
    'conditions' => [
        ['priority' => 1, 'amount' => 0, 'type' => 'above', 'total' => 450],
        ['priority' => 2, 'amount' => 55, 'type' => 'above', 'total' => 220],
        ['priority' => 3, 'amount' => 85, 'type' => 'all', 'total' => 0],
    ],
]);
$zonaPronaf->color = '#4CC3D9';
$zonaPronaf->save();

say('Delivery areas: El Paso (2) + Cd Juárez (2)');
// ---------------------------------------------------------------------------
// Mealtimes
// ---------------------------------------------------------------------------
Mealtime::query()->delete();
$mealtimes = [
    'breakfast' => Mealtime::create([
        'mealtime_name' => t('Breakfast', 'Desayuno'),
        'start_time' => '07:00:00',
        'end_time' => '11:00:00',
        'mealtime_status' => 1,
        'validity' => 'daily',
    ]),
    'lunch' => Mealtime::create([
        'mealtime_name' => t('Lunch', 'Almuerzo'),
        'start_time' => '11:00:00',
        'end_time' => '16:00:00',
        'mealtime_status' => 1,
        'validity' => 'daily',
    ]),
    'dinner' => Mealtime::create([
        'mealtime_name' => t('Dinner', 'Cena'),
        'start_time' => '16:00:00',
        'end_time' => '22:00:00',
        'mealtime_status' => 1,
        'validity' => 'daily',
    ]),
    'late' => Mealtime::create([
        'mealtime_name' => t('Late night', 'Noche'),
        'start_time' => '22:00:00',
        'end_time' => '23:59:00',
        'mealtime_status' => 1,
        'validity' => 'recurring',
        'recurring_every' => ['5', '6'],
        'recurring_from' => '22:00:00',
        'recurring_to' => '23:59:00',
    ]),
    // TI treats multiple mealtimes on one item as AND (all must match).
    // Attach this single window so tacos stay orderable outside lunch hours.
    'allday' => Mealtime::create([
        'mealtime_name' => t('All day', 'Todo el día'),
        'start_time' => '00:00:00',
        'end_time' => '23:59:00',
        'mealtime_status' => 1,
        'validity' => 'daily',
    ]),
];
foreach ($mealtimes as $mealtime) {
    $mealtime->locations()->sync($bothLocationIds);
}
say('Mealtimes: Breakfast, Lunch, Dinner, Late night, All day (both locations)');
// ---------------------------------------------------------------------------
// Ingredients / allergens
// ---------------------------------------------------------------------------
$ingredients = [];
foreach ([
    'cilantro' => [t('Cilantro', 'Cilantro'), t('Fresh cilantro', 'Cilantro fresco'), 0],
    'cebolla' => [t('Onion', 'Cebolla'), t('White onion', 'Cebolla blanca'), 0],
    'pina' => [t('Pineapple', 'Piña'), t('Pineapple', 'Piña'), 0],
    'achiote' => [t('Achiote', 'Achiote'), t('Achiote marinade', 'Marinado de achiote'), 0],
    'trigo' => [t('Wheat', 'Trigo'), t('Flour tortilla — contains wheat', 'Tortilla de harina — contiene trigo'), 1],
    'lacteos' => [t('Dairy', 'Lácteos'), t('Crema / cheese — contains dairy', 'Crema / queso — contiene lácteos'), 1],
] as $key => [$name, $description, $allergen]) {
    $ingredient = Ingredient::create([
        'name' => $name,
        'description' => $description,
        'status' => 1,
        'is_allergen' => $allergen,
    ]);
    $ingredients[$key] = $ingredient;
}
say('Ingredients + allergens seeded');

// ---------------------------------------------------------------------------
// Categories
// ---------------------------------------------------------------------------
$categoryIds = [];
foreach ([
    [t('Breakfast', 'Desayunos'), 'desayunos', t('Morning favorites 7–11am: pambazo & torta de chilaquil.', 'Antojitos de la mañana (7–11am): pambazo y torta de chilaquil.'), 0],
    [t('Tacos', 'Tacos'), 'tacos', t('Suadero, trompo al pastor, cabeza & costilla.', 'Suadero, pastor en trompo, cabeza y costilla.'), 1],
    [t('Combos', 'Combos'), 'combos', t('Plates and shareables.', 'Órdenes y platos para compartir.'), 2],
    [t('Drinks', 'Bebidas'), 'bebidas', t('Aguas frescas & soft drinks.', 'Aguas frescas y refrescos.'), 3],
    [t('Sides', 'Extras'), 'extras', t('Guacamole, grilled onions & more.', 'Guacamole, cebollitas y más.'), 4],
] as [$name, $slug, $description, $priority]) {
    $category = Category::create([
        'name' => $name,
        'permalink_slug' => $slug,
        'description' => $description,
        'priority' => $priority,
        'status' => 1,
    ]);
    $category->locations()->sync($bothLocationIds);
    $categoryIds[$slug] = $category->getKey();
}
if (method_exists(Category::class, 'fixTree')) {
    Category::fixTree();
}
say('Categories: Breakfast, Tacos, Combos, Drinks, Sides (both locations)');

// ---------------------------------------------------------------------------
// Menu options (USD extras for El Paso, MXN extras for Cd Juárez)
// ---------------------------------------------------------------------------
$makeOptions = function (int $forLocationId, float $flourExtra, float $cremaExtra, float $quesoExtra, string $extraLabel) {
    $fmt = static fn (float $n): string => fmod($n, 1.0) === 0.0
        ? (string)(int)$n
        : number_format($n, 2, '.', '');

    $tortilla = MenuOption::create(['option_name' => t('Tortilla', 'Tortilla'), 'display_type' => 'radio', 'priority' => 1]);
    foreach ([
        [t('Corn', 'Maíz'), 0, 1],
        [t("Flour (+{$extraLabel}{$fmt($flourExtra)})", "Harina (+{$extraLabel}{$fmt($flourExtra)})"), $flourExtra, 2],
    ] as [$name, $price, $priority]) {
        $tortilla->option_values()->create(['name' => $name, 'price' => $price, 'priority' => $priority]);
    }
    $tortilla->locations()->sync([$forLocationId]);
    $tortilla->load('option_values');

    $salsa = MenuOption::create(['option_name' => t('Salsa & toppings', 'Salsa y extras'), 'display_type' => 'checkbox', 'priority' => 2]);
    foreach ([
        [t('Green salsa', 'Salsa verde'), 0, 1],
        [t('Red salsa', 'Salsa roja'), 0, 2],
        [t('Habanero (spicy)', 'Habanero'), 0, 3],
        [t('Cilantro & onion', 'Cilantro y cebolla'), 0, 4],
        [t('Lime', 'Limón'), 0, 5],
        [t("Crema (+{$extraLabel}{$fmt($cremaExtra)})", "Crema (+{$extraLabel}{$fmt($cremaExtra)})"), $cremaExtra, 6],
        [t("Queso fresco (+{$extraLabel}{$fmt($quesoExtra)})", "Queso fresco (+{$extraLabel}{$fmt($quesoExtra)})"), $quesoExtra, 7],
    ] as [$name, $price, $priority]) {
        $salsa->option_values()->create(['name' => $name, 'price' => $price, 'priority' => $priority]);
    }
    $salsa->locations()->sync([$forLocationId]);
    $salsa->load('option_values');

    $spice = MenuOption::create(['option_name' => t('Spice level', 'Nivel de picante'), 'display_type' => 'radio', 'priority' => 3]);
    foreach ([
        [t('Mild', 'Suave'), 0, 1],
        [t('Medium', 'Medio'), 0, 2],
        [t('Hot', 'Picante'), 0, 3],
    ] as [$name, $price, $priority]) {
        $spice->option_values()->create(['name' => $name, 'price' => $price, 'priority' => $priority]);
    }
    $spice->locations()->sync([$forLocationId]);
    $spice->load('option_values');

    return compact('tortilla', 'salsa', 'spice');
};

$optsUsd = $makeOptions($locationId, 0.50, 0.50, 0.75, '$');
$optsMxn = $makeOptions($juarezId, 10, 10, 15, '$');
say('Options: Tortilla, Salsa, Spice level (USD + MXN)');
// ---------------------------------------------------------------------------
// Menus (same items; El Paso = USD, Cd Juárez = MXN)
// ---------------------------------------------------------------------------
$menuDefs = [
    [
        'menu_name' => 'Pambazo',
        'menu_description' => t(
            'Guajillo-dipped bolillo stuffed with potato & chorizo, lettuce, crema and cheese. Mexico City breakfast classic.',
            'Bolillo bañado en salsa guajillo, relleno de papa y chorizo, con lechuga, crema y queso. Clásico de desayuno chilango.',
        ),
        'menu_price' => 8.50,
        'menu_price_mxn' => 85,
        'menu_priority' => 1,
        'category' => 'desayunos',
        'mealtimes' => ['breakfast'],
        'ingredients' => ['trigo', 'lacteos', 'cebolla'],
        'options' => ['spice'],
        'special' => null,
        'special_mxn' => null,
        'stock' => 40,
        'photo' => '/opt/seed/images/pambazo.png',
    ],
    [
        'menu_name' => 'Torta de Chilaquil',
        'menu_description' => t(
            'Hearty torta of green or red chilaquiles with crema, cheese, onion, and chicken or egg.',
            'Torta generosa de chilaquiles verdes o rojos, con crema, queso, cebolla y un toque de pollo o huevo al gusto.',
        ),
        'menu_price' => 9.25,
        'menu_price_mxn' => 95,
        'menu_priority' => 2,
        'category' => 'desayunos',
        'mealtimes' => ['breakfast'],
        'ingredients' => ['trigo', 'lacteos', 'cebolla'],
        'options' => ['salsa', 'spice'],
        'special' => null,
        'special_mxn' => null,
        'stock' => 35,
        'photo' => '/opt/seed/images/torta-de-chilaquil.png',
    ],
    [
        'menu_name' => 'Taco de Suadero',
        'menu_description' => t(
            'Slow-cooked suadero on a corn tortilla with cilantro & onion.',
            'Suadero suave, cocido lento al estilo capitalino. En tortilla de maíz con cilantro y cebolla.',
        ),
        'menu_price' => 3.50,
        'menu_price_mxn' => 35,
        'menu_priority' => 1,
        'category' => 'tacos',
        'mealtimes' => ['allday'],
        'ingredients' => ['cilantro', 'cebolla'],
        'options' => ['tortilla', 'salsa', 'spice'],
        'special' => null,
        'special_mxn' => null,
        'stock' => null,
        'photo' => '/opt/seed/images/taco-de-suadero.png',
    ],
    [
        'menu_name' => t('Taco al Pastor (trompo)', 'Taco al pastor (trompo)'),
        'menu_description' => t(
            'Achiote-marinated pastor carved from the trompo, with pineapple.',
            'Pastor marinados en achiote, asados en trompo, con piña. Auténtico sabor de taquería.',
        ),
        'menu_price' => 3.75,
        'menu_price_mxn' => 38,
        'menu_priority' => 2,
        'category' => 'tacos',
        'mealtimes' => ['allday'],
        'ingredients' => ['cilantro', 'cebolla', 'pina', 'achiote'],
        'options' => ['tortilla', 'salsa', 'spice'],
        'special' => ['type' => 'P', 'special_price' => 15, 'validity' => 'forever'],
        'special_mxn' => ['type' => 'P', 'special_price' => 15, 'validity' => 'forever'],
        'stock' => 200,
        'photo' => '/opt/seed/images/taco-al-pastor.jpg',
    ],
    [
        'menu_name' => 'Taco de Cabeza',
        'menu_description' => t(
            'Tender, well-seasoned beef cabeza — a trompo & plancha classic.',
            'Cabeza de res suave y jugosa, bien sazonada. Un clásico de trompo y plancha.',
        ),
        'menu_price' => 3.50,
        'menu_price_mxn' => 35,
        'menu_priority' => 3,
        'category' => 'tacos',
        'mealtimes' => ['allday'],
        'ingredients' => ['cilantro', 'cebolla'],
        'options' => ['tortilla', 'salsa', 'spice'],
        'special' => null,
        'special_mxn' => null,
        'stock' => null,
        'photo' => '/opt/seed/images/taco-de-cabeza.png',
    ],
    [
        'menu_name' => 'Taco de Costilla',
        'menu_description' => t(
            'Shredded beef rib, juicy and rich — perfect with green salsa.',
            'Costilla de res deshebrada, jugosa y con hueso de sabor. Ideal con salsa verde.',
        ),
        'menu_price' => 4.25,
        'menu_price_mxn' => 42,
        'menu_priority' => 4,
        'category' => 'tacos',
        'mealtimes' => ['allday'],
        'ingredients' => ['cilantro', 'cebolla'],
        'options' => ['tortilla', 'salsa', 'spice'],
        'special' => ['type' => 'F', 'special_price' => 3.75, 'validity' => 'period'],
        'special_mxn' => ['type' => 'F', 'special_price' => 38, 'validity' => 'period'],
        'stock' => 80,
        'photo' => '/opt/seed/images/taco-de-costilla.png',
    ],
    [
        'menu_name' => t('3 Tacos mix & match', 'Orden de 3 tacos'),
        'menu_description' => t(
            'Pick any three tacos: suadero, pastor, cabeza or costilla. Includes grilled onions.',
            'Escoge tres tacos: suadero, pastor, cabeza o costilla. Incluye cebollitas.',
        ),
        'menu_price' => 10.50,
        'menu_price_mxn' => 105,
        'menu_priority' => 5,
        'category' => 'combos',
        'mealtimes' => ['allday'],
        'ingredients' => ['cilantro', 'cebolla'],
        'options' => ['tortilla', 'salsa'],
        'special' => null,
        'special_mxn' => null,
        'stock' => null,
        'photo' => '/opt/seed/images/3-tacos-mix-match.png',
    ],
    [
        'menu_name' => 'Plato Taconazo',
        'menu_description' => t(
            'Four pastor tacos with rice, beans and guacamole.',
            'Cuatro tacos al pastor, arroz, frijoles y guacamole. Para el antojo grande.',
        ),
        'menu_price' => 14.95,
        'menu_price_mxn' => 150,
        'menu_priority' => 6,
        'category' => 'combos',
        'mealtimes' => ['allday'],
        'ingredients' => ['cilantro', 'cebolla', 'pina', 'achiote'],
        'options' => ['spice'],
        'special' => null,
        'special_mxn' => null,
        'stock' => null,
        'photo' => '/opt/seed/images/plato-taconazo.jpg',
    ],
    [
        'menu_name' => 'Horchata',
        'menu_description' => t(
            'House rice & cinnamon agua fresca. 16 oz.',
            'Horchata casera de arroz con canela. 16 oz.',
        ),
        'menu_description_mxn' => t(
            'House rice & cinnamon agua fresca. 473 ml.',
            'Horchata casera de arroz con canela. 473 ml.',
        ),
        'menu_price' => 3.00,
        'menu_price_mxn' => 30,
        'menu_priority' => 10,
        'category' => 'bebidas',
        'mealtimes' => ['allday'],
        'ingredients' => ['lacteos'],
        'options' => [],
        'special' => null,
        'special_mxn' => null,
        'stock' => 50,
    ],
    [
        'menu_name' => 'Jamaica',
        'menu_description' => t(
            'Fresh hibiscus agua fresca, lightly sweet. 16 oz.',
            'Flor de jamaica fresca, ligeramente dulce. 16 oz.',
        ),
        'menu_description_mxn' => t(
            'Fresh hibiscus agua fresca, lightly sweet. 473 ml.',
            'Flor de jamaica fresca, ligeramente dulce. 473 ml.',
        ),
        'menu_price' => 3.00,
        'menu_price_mxn' => 30,
        'menu_priority' => 11,
        'category' => 'bebidas',
        'mealtimes' => ['allday'],
        'ingredients' => [],
        'options' => [],
        'special' => null,
        'special_mxn' => null,
        'stock' => 50,
    ],
    [
        'menu_name' => t('Mexican Coke', 'Coca mexicana'),
        'menu_description' => t(
            'Coca-Cola from Mexico in a glass bottle.',
            'Coca-Cola de México en botella de vidrio.',
        ),
        'menu_price' => 2.75,
        'menu_price_mxn' => 28,
        'menu_priority' => 12,
        'category' => 'bebidas',
        'mealtimes' => ['allday'],
        'ingredients' => [],
        'options' => [],
        'special' => null,
        'special_mxn' => null,
        'stock' => 100,
    ],
    [
        'menu_name' => t('Guacamole & chips', 'Guacamole y totopos'),
        'menu_description' => t(
            'Fresh daily guacamole with chips.',
            'Guacamole fresco del día con totopos.',
        ),
        'menu_price' => 6.50,
        'menu_price_mxn' => 65,
        'menu_priority' => 20,
        'category' => 'extras',
        'mealtimes' => ['allday'],
        'ingredients' => [],
        'options' => [],
        'special' => null,
        'special_mxn' => null,
        'stock' => null,
    ],
    [
        'menu_name' => t('Grilled onions', 'Cebollitas'),
        'menu_description' => t(
            'Cambray onions grilled on the plancha.',
            'Cebollitas cambray asadas a la plancha.',
        ),
        'menu_price' => 2.50,
        'menu_price_mxn' => 25,
        'menu_priority' => 21,
        'category' => 'extras',
        'mealtimes' => ['allday'],
        'ingredients' => ['cebolla'],
        'options' => [],
        'special' => null,
        'special_mxn' => null,
        'stock' => null,
    ],
    [
        'menu_name' => 'Esquites',
        'menu_description' => t(
            'Street-corn cup with mayo, cheese, chile and lime.',
            'Maíz en vaso con mayonesa, queso, chile y limón.',
        ),
        'menu_price' => 4.50,
        'menu_price_mxn' => 45,
        'menu_priority' => 22,
        'category' => 'extras',
        'mealtimes' => ['allday'],
        'ingredients' => ['lacteos'],
        'options' => ['spice'],
        'special' => null,
        'special_mxn' => null,
        'stock' => null,
    ],
];

$seedMenusForLocation = function (
    array $menuDefs,
    int $forLocationId,
    array $opts,
    string $priceKey,
    string $specialKey,
    string $descriptionKey,
    string $currencyLabel,
) use ($categoryIds, $mealtimes, $ingredients) {
    $menusByName = [];

    foreach ($menuDefs as $data) {
        $optionKeys = $data['options'];
        $categorySlug = $data['category'];
        $mealtimeKeys = $data['mealtimes'];
        $ingredientNames = $data['ingredients'];
        $special = $data[$specialKey] ?? null;
        $stockQty = $data['stock'];
        $price = $data[$priceKey];
        $description = $data[$descriptionKey] ?? $data['menu_description'];
        $photo = $data['photo'] ?? null;
        unset(
            $data['options'],
            $data['category'],
            $data['mealtimes'],
            $data['ingredients'],
            $data['special'],
            $data['special_mxn'],
            $data['stock'],
            $data['menu_price'],
            $data['menu_price_mxn'],
            $data['menu_description'],
            $data['menu_description_mxn'],
            $data['photo'],
        );

        $data['menu_description'] = $description;
        $data['menu_price'] = $price;
        $data['minimum_qty'] = 1;
        $data['menu_status'] = 1;

        $menu = Menu::create($data);
        if (is_string($photo) && $photo !== '') {
            attachLocationPhoto($menu, $photo, 'thumb');
        }
        $menu->categories()->sync([$categoryIds[$categorySlug]]);
        $menu->locations()->sync([$forLocationId]);
        $menu->mealtimes()->sync(array_map(fn($k) => $mealtimes[$k]->getKey(), $mealtimeKeys));

        $ingredientIds = [];
        foreach ($ingredientNames as $iname) {
            if (isset($ingredients[$iname])) {
                $ingredientIds[] = $ingredients[$iname]->getKey();
            }
        }
        if ($ingredientIds) {
            $menu->ingredients()->sync($ingredientIds);
        }

        foreach ($optionKeys as $priority => $optionKey) {
            $option = $opts[$optionKey];
            $isTortilla = $optionKey === 'tortilla';
            $menuOption = $menu->menu_options()->create([
                'option_id' => $option->getKey(),
                'is_required' => $isTortilla ? 1 : 0,
                'priority' => $priority + 1,
                'min_selected' => $isTortilla ? 1 : 0,
                'max_selected' => $isTortilla ? 1 : 0,
            ]);
            foreach ($option->option_values as $optionValue) {
                $menuOption->menu_option_values()->create([
                    'option_value_id' => $optionValue->getKey(),
                    'override_price' => $optionValue->price,
                    'priority' => $optionValue->priority,
                ]);
            }
        }

        if ($special) {
            $start = now()->subDay();
            $end = now()->addMonths(3);
            DB::table('menus_specials')->insert([
                'menu_id' => $menu->getKey(),
                'start_date' => $start,
                'end_date' => $end,
                'special_price' => $special['special_price'],
                'special_status' => 1,
                'type' => $special['type'],
                'validity' => $special['validity'],
                'recurring_every' => null,
                'recurring_from' => null,
                'recurring_to' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($stockQty !== null) {
            // quantity is guarded on Stock — set it via updateStock, not create().
            $stock = Stock::query()->create([
                'location_id' => $forLocationId,
                'stockable_id' => $menu->getKey(),
                'stockable_type' => 'menus',
                'low_stock_alert' => 1,
                'low_stock_threshold' => 10,
                'is_tracked' => 1,
            ]);
            $stock->updateStock((int) $stockQty, Stock::STATE_RECOUNT);
        }

        $enName = is_array($data['menu_name'] ?? null)
            ? (string)($data['menu_name']['en'] ?? '')
            : (string)$menu->menu_name;
        $menusByName[$enName] = $menu;
        say("Menu [{$currencyLabel}]: {$enName} (\${$menu->menu_price})");
    }

    return $menusByName;
};

$menusByName = $seedMenusForLocation($menuDefs, $locationId, $optsUsd, 'menu_price', 'special', 'menu_description', 'USD');
$seedMenusForLocation($menuDefs, $juarezId, $optsMxn, 'menu_price_mxn', 'special_mxn', 'menu_description_mxn', 'MXN');

// ---------------------------------------------------------------------------
// Coupons (USD thresholds for El Paso; MXN for Cd Juárez)
// ---------------------------------------------------------------------------
$coupons = [
    [
        'name' => 'Welcome',
        'code' => 'BIENVENIDO',
        'type' => 'P',
        'discount' => 10,
        'min_total' => 20,
        'description' => '10% off first order over $20 (El Paso)',
        'customer_redemptions' => 1,
        'validity' => 'forever',
        'locations' => [$locationId],
    ],
    [
        'name' => 'Bienvenido Juárez',
        'code' => 'BIENVENIDOJUAREZ',
        'type' => 'P',
        'discount' => 10,
        'min_total' => 200,
        'description' => '10% de descuento en pedidos de $200+ (Cd Juárez)',
        'customer_redemptions' => 1,
        'validity' => 'forever',
        'locations' => [$juarezId],
    ],
    [
        'name' => 'Taco Wednesday',
        'code' => 'TACOMIERCOLES',
        'type' => 'F',
        'discount' => 5,
        'min_total' => 15,
        'description' => '$5 off $15+ every Wednesday (El Paso)',
        'customer_redemptions' => 0,
        'validity' => 'recurring',
        'recurring_every' => ['Wed'],
        'recurring_from_time' => '11:00:00',
        'recurring_to_time' => '22:00:00',
        'locations' => [$locationId],
    ],
    [
        'name' => 'Taco miércoles Juárez',
        'code' => 'TACOMIERCOLESJRZ',
        'type' => 'F',
        'discount' => 50,
        'min_total' => 150,
        'description' => '$50 de descuento en $150+ los miércoles (Cd Juárez)',
        'customer_redemptions' => 0,
        'validity' => 'recurring',
        'recurring_every' => ['Wed'],
        'recurring_from_time' => '11:00:00',
        'recurring_to_time' => '22:00:00',
        'locations' => [$juarezId],
    ],
    [
        'name' => 'Free delivery',
        'code' => 'ENVIOGRATIS',
        'type' => 'F',
        'discount' => 4.99,
        'min_total' => 25,
        'description' => 'Covers Central El Paso delivery fee on $25+',
        'customer_redemptions' => 0,
        'validity' => 'forever',
        'apply_coupon_on' => 'delivery_fee',
        'locations' => [$locationId],
    ],
    [
        'name' => 'Envío gratis Juárez',
        'code' => 'ENVIOGRATISJRZ',
        'type' => 'F',
        'discount' => 55,
        'min_total' => 250,
        'description' => 'Cubre envío en Centro Juárez en pedidos de $250+',
        'customer_redemptions' => 0,
        'validity' => 'forever',
        'apply_coupon_on' => 'delivery_fee',
        'locations' => [$juarezId],
    ],
];
foreach ($coupons as $couponData) {
    $locationIds = $couponData['locations'];
    unset($couponData['locations']);
    $couponData['status'] = 1;
    $couponData['redemptions'] = 0;
    $couponData['min_menu_quantity'] = 0;
    $couponData['auto_apply'] = 0;
    $couponData['apply_coupon_on'] = $couponData['apply_coupon_on'] ?? 'whole_cart';
    $couponData['created_at'] = now();
    $couponData['updated_at'] = now();
    $coupon = Coupon::create($couponData);
    $coupon->locations()->sync($locationIds);
    say("Coupon: {$coupon->code}");
}

// ---------------------------------------------------------------------------
// Customers + addresses
// ---------------------------------------------------------------------------
$customerPassword = 'customer123!';
$customers = [];
foreach ([
    ['María', 'González', 'juanagallo@gmail.com', '(915) 555-1001', '1201 Magoffin Ave', '79901'],
    ['José', 'Ramírez', 'jose@example.com', '(915) 555-1002', '800 S El Paso St', '79901'],
    ['Ana', 'Torres', 'ana@example.com', '(915) 555-1003', '2200 N Piedras St', '79930'],
] as [$first, $last, $email, $phone, $street, $zip]) {
    $customer = Customer::query()->updateOrCreate(
        ['email' => $email],
        [
            'first_name' => $first,
            'last_name' => $last,
            'password' => $customerPassword,
            'telephone' => $phone,
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
        'address_1' => $street,
        'city' => 'El Paso',
        'state' => 'TX',
        'postcode' => $zip,
        'country_id' => 223,
    ]);
    $customer->address_id = $address->getKey();
    $customer->save();
    $customers[] = $customer;
    say("Customer: {$email} / customer123!");
}

// ---------------------------------------------------------------------------
// Reviews
// ---------------------------------------------------------------------------
$reviewTexts = [
    [5, 5, 5, 'Best trompo al pastor in El Paso — the spit is on point.'],
    [5, 4, 5, 'Suadero suavecito y las salsas caseras. Volveremos.'],
    [4, 5, 4, 'Fast pickup. The costilla taco is incredible.'],
];
foreach ($customers as $i => $customer) {
    [$q, $d, $s, $text] = $reviewTexts[$i];
    Review::query()->create([
        'customer_id' => $customer->getKey(),
        'reviewable_id' => $locationId,
        'reviewable_type' => 'locations',
        'author' => trim($customer->first_name.' '.$customer->last_name),
        'location_id' => $locationId,
        'quality' => $q,
        'delivery' => $d,
        'service' => $s,
        'review_text' => $text,
        'review_status' => 1,
        'created_at' => now()->subDays(7 - $i),
        'updated_at' => now()->subDays(7 - $i),
    ]);
}
say('Reviews: 3 customer reviews');

// ---------------------------------------------------------------------------
// Sample orders (admin Sales → Orders + customer account)
// ---------------------------------------------------------------------------
try {
    $menu = fn(string $name): ?Menu => $menusByName[$name] ?? null;
    $pastor = $menu('Taco al Pastor (trompo)');
    $suadero = $menu('Taco de Suadero');
    $costilla = $menu('Taco de Costilla');
    $horchata = $menu('Horchata');
    $jamaica = $menu('Jamaica');
    $plato = $menu('Plato Taconazo');
    $guac = $menu('Guacamole & chips');
    $pambazo = $menu('Pambazo');

    $seedOrder = function (
        Customer $customer,
        string $type,
        int $statusId,
        Carbon\CarbonInterface $when,
        string $time,
        bool $asap,
        string $comment,
        array $lines,
        float $deliveryFee = 0,
    ) use ($locationId): void {
        $order = new Order;
        $order->forceFill([
            'customer_id' => $customer->getKey(),
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'telephone' => $customer->telephone,
            'location_id' => $locationId,
            'address_id' => $type === 'delivery' ? $customer->address_id : null,
            'comment' => $comment,
            'payment' => 'cod',
            'order_type' => $type,
            'order_date' => $when->toDateString(),
            'order_time' => $time,
            'order_time_is_asap' => $asap ? 1 : 0,
            'status_id' => $statusId,
            'processed' => $statusId === 9 ? 0 : 1,
            'hash' => md5(uniqid('eltaconazo', true)),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'El Taconazo seeder',
            'cart' => [],
            'total_items' => 0,
            'order_total' => 0,
            'created_at' => $when,
            'updated_at' => $when,
            'status_updated_at' => $when,
        ]);
        $order->saveQuietly();

        foreach ($lines as [$item, $qty]) {
            if (!$item instanceof Menu) {
                continue;
            }
            $price = (float)$item->menu_price;
            $order->menus()->create([
                'menu_id' => $item->getKey(),
                'name' => is_array($item->menu_name) ? ($item->menu_name['en'] ?? 'Menu') : (string)$item->menu_name,
                'quantity' => $qty,
                'price' => $price,
                'subtotal' => round($price * $qty, 2),
                'comment' => '',
                'option_values' => [],
            ]);
        }

        $subtotal = (float)$order->menus()->sum('subtotal');
        $order->totals()->create([
            'code' => 'subtotal',
            'title' => 'Sub Total',
            'value' => $subtotal,
            'priority' => 0,
            'is_summable' => 0,
        ]);
        if ($deliveryFee > 0) {
            $order->totals()->create([
                'code' => 'delivery',
                'title' => 'Delivery',
                'value' => $deliveryFee,
                'priority' => 2,
                'is_summable' => 1,
            ]);
        }
        $order->totals()->create([
            'code' => 'total',
            'title' => 'Order Total',
            'value' => $subtotal + $deliveryFee,
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

        say(sprintf(
            'Order #%d %s %s $%s (%s)',
            $order->getKey(),
            $customer->email,
            $type,
            number_format((float)$order->fresh()->order_total, 2),
            $statusRow->status_name ?? $statusId,
        ));
    };

    if ($customers && $pastor && $suadero) {
        $seedOrder($customers[0], 'delivery', 5, now()->subDays(2)->setTime(19, 10), '19:15:00', false, 'Extra salsa verde on the pastor.', [
            [$pastor, 4],
            [$horchata, 2],
        ], 2.99);
        $seedOrder($customers[1], 'collection', 3, now()->subHours(3)->setTime(12, 5), '12:20:00', true, 'Para recoger — no cebolla on the suadero.', [
            [$suadero, 3],
            [$costilla, 2],
            [$jamaica, 1],
        ]);
        $seedOrder($customers[2], 'delivery', 1, now()->subMinutes(40), '18:45:00', true, 'Leave at the door if no one answers.', [
            [$plato, 1],
            [$guac, 1],
        ], 2.99);
        $seedOrder($customers[0], 'collection', 9, now()->subDays(6)->setTime(8, 30), '08:45:00', false, 'Canceled — running late for work.', [
            [$pambazo, 2],
        ]);
    } else {
        say('Orders skipped: menus or customers missing');
    }
} catch (Throwable $e) {
    say('Orders skipped: '.$e->getMessage());
}

// ---------------------------------------------------------------------------
// Pages (same slugs; EN/ES via Translate)
// ---------------------------------------------------------------------------
$enId = (int)(Language::query()->where('code', 'en')->value('language_id') ?: 1);

Page::query()->whereIn('permalink_slug', ['acerca-de', 'aviso-de-privacidad', 'terminos-y-condiciones'])->delete();

$about = Page::query()->where('permalink_slug', 'about-us')->first();
if ($about) {
    // Page::getContentAttribute + Translate: without an `en` content translation,
    // Purify mutates null→'' and the storefront body renders blank.
    $aboutContentEn = '<p><strong>El Taconazo</strong> started with Don Lucio, a cook who learned the trompo in Mexico City. He moved north looking for work, opened a small kitchen near Tecnológico in Ciudad Juárez, and kept the trompo turning for neighbors who came back for suadero, cabeza, pastor, and costilla.</p><p>Years later the line spilled past Juárez. Don Lucio opened on Alameda Ave in El Paso so the same trompo could feed both sides of the border. Same recipes: carve all day, breakfast from 7am with pambazo and torta de chilaquil.</p><p>Come in, pick up, or order delivery. We cook like it\'s for family, because it is.</p>';
    $aboutMetaEn = 'Don Lucio\'s taquería: Mexico City trompo, Juárez roots, El Paso on Alameda. Pastor, suadero, breakfast from 7am.';
    $about->language_id = $enId;
    $about->title = 'About El Taconazo';
    $about->content = $aboutContentEn;
    $about->meta_description = $aboutMetaEn;
    $about->status = 1;
    $about->save();
    $about->setAttributeTranslatedValue('content', $aboutContentEn, 'en');
    $about->setAttributeTranslatedValue('meta_description', $aboutMetaEn, 'en');
    $about->setAttributeTranslatedValue('title', 'Acerca de El Taconazo', 'es_MX');
    $about->setAttributeTranslatedValue('content', '<p><strong>El Taconazo</strong> empezó con Don Lucio, un cocinero que aprendió el trompo en la Ciudad de México. Se vino al norte buscando jale, abrió una cocina chica cerca del Tecnológico en Ciudad Juárez y mantuvo el trompo girando para el barrio que regresaba por suadero, cabeza, pastor y costilla.</p><p>Con el tiempo la fila ya no cabía en Juárez. Don Lucio abrió en Alameda Ave en El Paso para que el mismo trompo alimentara de los dos lados. Las mismas recetas: pastor todo el día, desayuno desde las 7am con pambazo y torta de chilaquil.</p><p>Pasa, pide para llevar o a domicilio. Cocina de familia, de verdad.</p>', 'es_MX');
    $about->setAttributeTranslatedValue('meta_description', 'La taquería de Don Lucio: trompo de la CDMX, raíces en Juárez, El Paso en Alameda. Pastor, suadero, desayuno desde las 7am.', 'es_MX');
    $about->save();
}

$policy = Page::query()->where('permalink_slug', 'policy')->first();
if ($policy) {
    $policyContentEn = '<p>El Taconazo respects your privacy. We only use your contact and order details to prepare and deliver your food.</p>';
    $policy->language_id = $enId;
    $policy->title = 'Privacy Policy';
    $policy->content = $policyContentEn;
    $policy->status = 1;
    $policy->save();
    $policy->setAttributeTranslatedValue('content', $policyContentEn, 'en');
    $policy->setAttributeTranslatedValue('title', 'Aviso de privacidad', 'es_MX');
    $policy->setAttributeTranslatedValue('content', '<p>El Taconazo respeta tu privacidad. Solo usamos tus datos de contacto y pedido para preparar y entregar tu comida.</p>', 'es_MX');
    $policy->save();
}

$terms = Page::query()->where('permalink_slug', 'terms-and-conditions')->first();
if ($terms) {
    $termsContentEn = '<p>Orders are subject to availability of meats from the trompo. Allergies: flour tortillas contain wheat; crema and queso contain dairy.</p>';
    $terms->language_id = $enId;
    $terms->title = 'Terms & Conditions';
    $terms->content = $termsContentEn;
    $terms->status = 1;
    $terms->save();
    $terms->setAttributeTranslatedValue('content', $termsContentEn, 'en');
    $terms->setAttributeTranslatedValue('title', 'Términos y condiciones', 'es_MX');
    $terms->setAttributeTranslatedValue('content', '<p>Los pedidos están sujetos a la disponibilidad de carnes del trompo. Alérgenos: las tortillas de harina contienen trigo; la crema y el queso contienen lácteos.</p>', 'es_MX');
    $terms->save();
}
say('Pages: About / Privacy / Terms (EN + ES translations)');

// Footer / info menu: store lang keys so EN|ES both work (MenuItem is not Translate-aware)
$menuTitleMap = [
    'About Us' => 'igniter.orange::default.menu_about',
    'Privacy Policy' => 'igniter.orange::default.menu_privacy',
    'Terms & Conditions' => 'igniter.orange::default.menu_terms',
    'Terms and Conditions' => 'igniter.orange::default.menu_terms',
];
foreach ($menuTitleMap as $from => $to) {
    $updated = DB::table('igniter_pages_menu_items')->where('title', $from)->update(['title' => $to]);
    if ($updated) {
        say("Menu title → {$from} ⇒ {$to}");
    }
}

// ---------------------------------------------------------------------------
// Banners + newsletter subscribers
// ---------------------------------------------------------------------------
$esId = (int)(Language::query()->where('code', 'es_MX')->value('language_id') ?: 0);
if (class_exists(Banner::class)) {
    Banner::query()->create([
        'name' => 'Pastor special',
        'code' => 'pastor-special',
        'type' => 'custom',
        'click_url' => '/el-taconazo-alameda-ave/menus',
        'language_id' => $enId,
        'alt_text' => '15% off taco al pastor',
        'custom_code' => '<div class="p-3 text-center bg-warning-subtle"><strong>¡Taco al Pastor!</strong> 15% off the trompo special.</div>',
        'status' => 1,
    ]);
    Banner::query()->create([
        'name' => 'Coupon welcome',
        'code' => 'welcome-banner',
        'type' => 'custom',
        'click_url' => '/el-taconazo-alameda-ave/menus',
        'language_id' => $enId,
        'alt_text' => 'Use code BIENVENIDO',
        'custom_code' => '<div class="p-3 text-center"><strong>New here?</strong> Use code <code>BIENVENIDO</code> for 10% off $20+.</div>',
        'status' => 1,
    ]);
    if ($esId) {
        Banner::query()->create([
            'name' => 'Pastor special ES',
            'code' => 'pastor-special-es',
            'type' => 'custom',
            'click_url' => '/el-taconazo-alameda-ave/menus',
            'language_id' => $esId,
            'alt_text' => '15% de descuento en taco al pastor',
            'custom_code' => '<div class="p-3 text-center bg-warning-subtle"><strong>¡Taco al Pastor!</strong> 15% de descuento en el especial del trompo.</div>',
            'status' => 1,
        ]);
        Banner::query()->create([
            'name' => 'Coupon welcome ES',
            'code' => 'welcome-banner-es',
            'type' => 'custom',
            'click_url' => '/el-taconazo-alameda-ave/menus',
            'language_id' => $esId,
            'alt_text' => 'Usa el código BIENVENIDO',
            'custom_code' => '<div class="p-3 text-center"><strong>¿Nuevo aquí?</strong> Usa el código <code>BIENVENIDO</code> para 10% de descuento en pedidos de $20+.</div>',
            'status' => 1,
        ]);
    }
    say('Banners: pastor special + welcome coupon (EN + ES)');
}

if (class_exists(Subscriber::class)) {
    foreach (['fan@example.com', 'elpaso.eats@example.com', 'tacolover@example.com'] as $email) {
        Subscriber::subscribe($email);
    }
    say('Newsletter subscribers: 3');
}

// ---------------------------------------------------------------------------
// Dining: rename area, add patio section, sample reservation
// ---------------------------------------------------------------------------
$diningArea = DiningArea::query()->where('location_id', $locationId)->first();
if ($diningArea) {
    $diningArea->name = 'El Taconazo Main Floor';
    $diningArea->description = 'Indoor dining with counter seating facing the trompo.';
    $diningArea->save();

    $section = DiningSection::query()->firstOrCreate(
        ['location_id' => $locationId, 'name' => 'Patio'],
        [
            'description' => 'Covered patio seating',
            'priority' => 1,
            'is_enabled' => 1,
            'color' => '#7BC8A4',
        ],
    );

    DiningTable::query()
        ->where('dining_area_id', $diningArea->getKey())
        ->orderBy('id')
        ->limit(4)
        ->update(['dining_section_id' => $section->getKey(), 'is_enabled' => 1]);

    say('Dining area renamed + Patio section');
}

try {
    if (class_exists(Reservation::class) && $customers) {
        $table = DiningTable::query()->where('is_enabled', 1)->first();
        $confirmedStatus = (int)(setting('confirmed_reservation_status') ?: setting('default_reservation_status') ?: 1);
        $reservation = new Reservation;
        $reservation->location_id = $locationId;
        $reservation->customer_id = $customers[0]->getKey();
        $reservation->first_name = $customers[0]->first_name;
        $reservation->last_name = $customers[0]->last_name;
        $reservation->email = $customers[0]->email;
        $reservation->telephone = $customers[0]->telephone;
        $reservation->reserve_date = now()->addDays(2)->toDateString();
        $reservation->reserve_time = '19:00:00';
        $reservation->guest_num = 4;
        $reservation->occasion_id = null;
        $reservation->comment = 'Window table if possible — celebrating with pastor and aguas.';
        $reservation->status_id = $confirmedStatus;
        $reservation->save();
        if ($table && method_exists($reservation, 'tables')) {
            $reservation->tables()->sync([$table->getKey()]);
        }
        say('Sample reservation for María (party of 4)');
    }
} catch (Throwable $e) {
    say('Reservation skipped: '.$e->getMessage());
}

try {
    Illuminate\Support\Facades\Artisan::call('cache:clear');
    Illuminate\Support\Facades\Artisan::call('config:clear');
} catch (Throwable) {
}

echo "\nDone. Alameda Ave → http://127.0.0.1:8001/el-taconazo-alameda-ave/menus (USD)\n";
echo "Tecnológico → http://127.0.0.1:8001/el-taconazo-tecnologico/menus (MXN)\n";
echo "Admin → http://127.0.0.1:8001/admin  (operations@taconazotaqueria.com / 12345678abc!)\n";
echo "Customer demo → juanagallo@gmail.com / customer123!\n";
echo "Coupons → BIENVENIDO, TACOMIERCOLES, ENVIOGRATIS\n";
echo "Orders → 4 sample orders in Admin → Sales → Orders\n";
echo "Language → EN/ES picker in the header (en + es_MX)\n";
