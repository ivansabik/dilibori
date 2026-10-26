#!/usr/bin/env php
<?php

/**
 * Rich El Taconazo (El Paso, TX) catalog seed.
 * Exercises menus, options, specials, mealtimes, allergens, delivery areas,
 * working hours, location order/reservation settings, pages, banners, stocks,
 * and dining layout. Customers, orders, reservations, coupons, reviews, and
 * subscribers come from seed/common/seed-demo-activity.php.
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
use Igniter\Cart\Models\Stock;
use Igniter\Frontend\Models\Banner;
use Igniter\Frontend\Models\Slider;
use Igniter\Local\Models\Location;
use Igniter\Local\Models\LocationSettings;
use Igniter\Local\Models\WorkingHour;
use Igniter\Main\Classes\MediaLibrary;
use Igniter\Main\Models\Theme;
use Igniter\Pages\Models\Page;
use Igniter\System\Facades\Assets;
use Igniter\Reservation\Models\DiningArea;
use Igniter\Reservation\Models\DiningSection;
use Igniter\Reservation\Models\DiningTable;
use Igniter\System\Models\Currency;
use Igniter\System\Models\Language;
use Igniter\User\Facades\AdminAuth;
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
function attachLocationPhoto($model, string $sourcePath, string $tag = 'thumb', bool $clear = true): void
{
    if (!is_file($sourcePath)) {
        throw new RuntimeException("Missing photo: {$sourcePath}");
    }

    if ($clear) {
        $model->clearMediaTag($tag);
    }
    $model->newMediaInstance()->addFromFile($sourcePath, $tag);
    $model = $model->fresh() ?? $model;

    $media = $model->getMedia($tag)->last();
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
$location = Location::query()
    ->where('permalink_slug', 'el-taconazo-alameda-ave')
    ->orderByDesc('is_default')
    ->orderBy('location_id')
    ->first()
    ?? Location::query()->orderBy('location_id')->first()
    ?? new Location;
$location->fill([
    'location_name' => 'El Taconazo - Alameda Ave',
    'location_email' => 'hola@eltaconazo.example',
    'description' => t(
        'Taquería on Alameda Ave. Breakfast (pambazo, torta de chilaquil), trompo al pastor, suadero, cabeza, and costilla. Come in, pick up, or get delivery.',
        'Taquería en Alameda Ave. Desayunos (pambazo, torta de chilaquil), trompo al pastor, suadero, cabeza y costilla. Pasa, pide para llevar o a domicilio.',
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
Location::query()
    ->where('permalink_slug', 'el-taconazo-alameda-ave')
    ->where('location_id', '!=', $locationId)
    ->get()
    ->each(fn (Location $extra) => $extra->delete());
say("Location #{$locationId}: El Taconazo - Alameda Ave, El Paso, TX");

// Cd Juárez location (same brand, MXN menu)
$locationJuarez = Location::query()
    ->whereIn('permalink_slug', ['el-taconazo-tecnologico', 'el-taconazo-cd-juarez'])
    ->first() ?? new Location;
$locationJuarez->fill([
    'location_name' => 'El Taconazo - Tecnológico',
    'location_email' => 'juarez@eltaconazo.example',
    'description' => t(
        'Near Tecnológico in Ciudad Juárez. Same trompo: pastor, suadero, cabeza, and costilla. Prices in pesos. Come in, pick up, or get delivery.',
        'Cerca del Tecnológico en Ciudad Juárez. El mismo trompo: pastor, suadero, cabeza y costilla. Precios en pesos. Pasa, pide para llevar o a domicilio.',
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
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';
$admin = User::query()->where('email', $adminEmail)->first()
    ?? User::query()->orderBy('user_id')->first();
if (!$admin) {
    if ($adminPassword === '') {
        $adminPassword = bin2hex(random_bytes(12));
        $passwordFile = base_path('storage/dilibori-admin-password');
        file_put_contents($passwordFile, $adminPassword);
        chmod($passwordFile, 0600);
        say('Generated admin password → storage/dilibori-admin-password');
    }
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
    say("Admin created → {$adminEmail}");
} else {
    $admin->email = $adminEmail;
    // Only reset when ADMIN_PASSWORD is set; avoid clobbering a random secret on re-seed.
    if ($adminPassword !== '') {
        $admin->password = $adminPassword;
    }
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
    'igniter_frontend_banners',
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
    ->whereIn('locationable_type', ['menus', 'categories', 'menu_options', 'mealtimes', 'ingredients'])
    ->delete();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

// Truncate dropped translation rows; reload so the next save is dirty.
$location = $location->fresh() ?? $location;
$location->description = t(
    'Taquería on Alameda Ave. Breakfast (pambazo, torta de chilaquil), trompo al pastor, suadero, cabeza, and costilla. Come in, pick up, or get delivery.',
    'Taquería en Alameda Ave. Desayunos (pambazo, torta de chilaquil), trompo al pastor, suadero, cabeza y costilla. Pasa, pide para llevar o a domicilio.',
);
$location->save();

$locationJuarez = $locationJuarez->fresh() ?? $locationJuarez;
$locationJuarez->description = t(
    'Near Tecnológico in Ciudad Juárez. Same trompo: pastor, suadero, cabeza, and costilla. Prices in pesos. Come in, pick up, or get delivery.',
    'Cerca del Tecnológico en Ciudad Juárez. El mismo trompo: pastor, suadero, cabeza y costilla. Precios en pesos. Pasa, pide para llevar o a domicilio.',
);
$locationJuarez->save();

$storePhoto = '/opt/seed/images/el-taconazo.png';
$platoPhoto = '/opt/seed/images/plato-taconazo.jpg';
$pastorPhoto = '/opt/seed/images/taco-al-pastor.jpg';
if (is_file($storePhoto)) {
    attachLocationPhoto($location, $storePhoto, 'thumb');
    say('Store photo → El Paso thumb');
} else {
    say('El Paso store photo skipped (missing '.$storePhoto.')');
}
// Food-first home slider: plato → pastor → storefront
if (class_exists(Slider::class)) {
    $slider = Slider::query()->where('code', 'home-slider')->first();
    if ($slider) {
        $slider->clearMediaTag('images');
        $slideOrder = [
            [$platoPhoto, 'Plato Taconazo'],
            [$pastorPhoto, 'Taco al pastor'],
            [$storePhoto, 'El Taconazo'],
        ];
        $first = true;
        foreach ($slideOrder as [$path, $label]) {
            if (!is_file($path)) {
                continue;
            }
            attachLocationPhoto($slider, $path, 'images', $first);
            $first = false;
            say("Slider ← {$label}");
        }
    }
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
    // Chile accent / concrete chrome (replaces Orange default #ff4900)
    $data['color-primary'] = '#C43C2C';
    $data['heading-color'] = '#111111';
    $data['header-accent'] = '#C43C2C';
    settingSet('header_accent', '#C43C2C');
    settingSet('hero_heading_color', '#111111');
    settingSet('hero_title', 'Trompo-carved pastor. Pickup or delivery.');
    settingSet('hero_subtitle', 'El Taconazo · El Paso & Ciudad Juárez');
    settingSet('hero_title_en', 'Trompo-carved pastor. Pickup or delivery.');
    settingSet('hero_subtitle_en', 'El Taconazo · El Paso & Ciudad Juárez');
    settingSet('hero_title_es_MX', 'Pastor del trompo. Para recoger o a domicilio.');
    settingSet('hero_subtitle_es_MX', 'El Taconazo · El Paso y Ciudad Juárez');

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
    $data['gdpr_cookie_message'] = 'We use cookies to run the storefront. Keep browsing if that is OK.';
    $data['gdpr_accept_text'] = 'OK';
    $data['gdpr_more_info_text'] = 'More information';
    $theme->data = $data;
    $theme->save();
    Theme::clearThemeInstances();

    try {
        Assets::buildBundles($theme->getTheme());
        // Compiled CSS lives under combiner/data/; shallow unlink leaves #ff4900.
        $combiner = storage_path('igniter/combiner');
        if (is_dir($combiner)) {
            Illuminate\Support\Facades\File::cleanDirectory($combiner);
        }
        say('Theme → chile primary + Instagram / Facebook / TikTok');
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
    'trigo' => [t('Wheat', 'Trigo'), t('Flour tortilla, contains wheat', 'Tortilla de harina, contiene trigo'), 1],
    'lacteos' => [t('Dairy', 'Lácteos'), t('Crema / cheese, contains dairy', 'Crema / queso, contiene lácteos'), 1],
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
    [t('Breakfast', 'Desayunos'), 'desayunos', t('Breakfast 7-11am: pambazo and torta de chilaquil.', 'Desayunos de 7 a 11am: pambazo y torta de chilaquil.'), 0],
    [t('Tacos', 'Tacos'), 'tacos', t('Suadero, trompo al pastor, cabeza, and costilla.', 'Suadero, pastor en trompo, cabeza y costilla.'), 1],
    [t('Combos', 'Combos'), 'combos', t('Plates and orders to share.', 'Órdenes y platos para compartir.'), 2],
    [t('Drinks', 'Bebidas'), 'bebidas', t('Aguas frescas and soft drinks.', 'Aguas frescas y refrescos.'), 3],
    [t('Sides', 'Extras'), 'extras', t('Guacamole, grilled onions, and more.', 'Guacamole, cebollitas y más.'), 4],
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
            'Bolillo dipped in guajillo, stuffed with potato and chorizo, lettuce, crema, and cheese. Mexico City breakfast classic.',
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
            'Torta of green or red chilaquiles with crema, cheese, onion, and chicken or egg.',
            'Torta de chilaquiles verdes o rojos, con crema, queso, cebolla y pollo o huevo al gusto.',
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
            'Slow-cooked suadero on a corn tortilla with cilantro and onion.',
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
            'Pastor marinado en achiote, asado en trompo, con piña.',
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
            'Tender, well-seasoned beef cabeza. A trompo and plancha classic.',
            'Cabeza de res suave y jugosa, bien sazonada. Clásico de trompo y plancha.',
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
            'Shredded beef rib, juicy and rich. Good with green salsa.',
            'Costilla de res deshebrada, jugosa y con hueso de sabor. Va bien con salsa verde.',
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
            'Four pastor tacos with rice, beans, and guacamole.',
            'Cuatro tacos al pastor, arroz, frijoles y guacamole.',
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
        'photo' => '/opt/seed/images/horchata.jpg',
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
        'photo' => '/opt/seed/images/jamaica.jpg',
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
        'photo' => '/opt/seed/images/mexican-coke.jpg',
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
        'photo' => '/opt/seed/images/guacamole-chips.jpg',
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
        'photo' => '/opt/seed/images/cebollitas.jpg',
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
        'photo' => '/opt/seed/images/esquites.jpg',
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

$seedMenusForLocation($menuDefs, $locationId, $optsUsd, 'menu_price', 'special', 'menu_description', 'USD');
$seedMenusForLocation($menuDefs, $juarezId, $optsMxn, 'menu_price_mxn', 'special_mxn', 'menu_description_mxn', 'MXN');

// Favoritos strip on home (Plato, 3-taco mix, pastor) for default location
$favoritoIds = Menu::query()
    ->where('menu_status', 1)
    ->whereHas('locations', fn ($q) => $q->where('locations.location_id', $locationId))
    ->where(function ($q) {
        $q->where('menu_name', 'like', '%Plato Taconazo%')
            ->orWhere('menu_name', 'like', '%mix%')
            ->orWhere('menu_name', 'like', '%Pastor%');
    })
    ->orderBy('menu_priority')
    ->limit(3)
    ->pluck('menu_id')
    ->all();
if ($favoritoIds !== []) {
    settingSet('taconazo_favoritos_ids', implode(',', $favoritoIds));
    say('Favoritos → menu IDs '.implode(',', $favoritoIds));
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
    $aboutContentEn = '<p><strong>El Taconazo</strong> started with Don Lucio, a cook who learned the trompo in Mexico City. He moved north looking for work, opened a small kitchen near Tecnológico in Ciudad Juárez, and kept the trompo turning for neighbors who came back for suadero, cabeza, pastor, and costilla.</p><p>Years later the line spilled past Juárez. Don Lucio opened on Alameda Ave in El Paso so the same trompo could feed both sides of the border. Same recipes: carve all day, breakfast from 7am with pambazo and torta de chilaquil.</p><p>Come in, pick up, or order delivery.</p>';
    $aboutMetaEn = 'Don Lucio\'s taquería on Alameda: Mexico City trompo, Juárez roots. Pastor, suadero, breakfast from 7am.';
    $about->language_id = $enId;
    $about->title = 'About El Taconazo';
    $about->content = $aboutContentEn;
    $about->meta_description = $aboutMetaEn;
    $about->status = 1;
    $about->save();
    $about->setAttributeTranslatedValue('content', $aboutContentEn, 'en');
    $about->setAttributeTranslatedValue('meta_description', $aboutMetaEn, 'en');
    $about->setAttributeTranslatedValue('title', 'Acerca de El Taconazo', 'es_MX');
    $about->setAttributeTranslatedValue('content', '<p><strong>El Taconazo</strong> empezó con Don Lucio, un cocinero que aprendió el trompo en la Ciudad de México. Se vino al norte buscando jale, abrió una cocina chica cerca del Tecnológico en Ciudad Juárez y mantuvo el trompo girando para el barrio que regresaba por suadero, cabeza, pastor y costilla.</p><p>Con el tiempo la fila ya no cabía en Juárez. Don Lucio abrió en Alameda Ave en El Paso para que el mismo trompo alimentara de los dos lados. Las mismas recetas: pastor todo el día, desayuno desde las 7am con pambazo y torta de chilaquil.</p><p>Pasa, pide para llevar o a domicilio.</p>', 'es_MX');
    $about->setAttributeTranslatedValue('meta_description', 'Taquería de Don Lucio en Alameda: trompo de la CDMX, raíces en Juárez. Pastor, suadero, desayuno desde las 7am.', 'es_MX');
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

// ---------------------------------------------------------------------------
// Dining: rename area, add patio section
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
    Illuminate\Support\Facades\Artisan::call('cache:clear');
    Illuminate\Support\Facades\Artisan::call('config:clear');
} catch (Throwable) {
}

echo "\nDone. Alameda Ave → http://127.0.0.1:8001/el-taconazo-alameda-ave/menus (USD)\n";
echo "Tecnológico → http://127.0.0.1:8001/el-taconazo-tecnologico/menus (MXN)\n";
echo "Admin → http://127.0.0.1:8001/admin  (operations@taconazotaqueria.com; make admin-pass)\n";
echo "Catalog only — demo orders/customers/coupons come from seed-demo-activity.php\n";
echo "Language → EN/ES picker in the header (en + es_MX)\n";
