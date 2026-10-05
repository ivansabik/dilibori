#!/usr/bin/env php
<?php

/**
 * Idempotent tenant seed from a JSON manifest.
 *
 * Modes:
 *   SEED_MODE=initial  create baseline site settings, languages, locations, admin
 *   SEED_MODE=update   upsert settings/locations/languages only (never truncate orders)
 *
 * Never truncates orders, customers, or reviews in any mode.
 */

declare(strict_types=1);

use Igniter\Local\Models\Location;
use Igniter\Local\Models\LocationSettings;
use Igniter\Main\Classes\MediaLibrary;
use Igniter\Main\Models\Theme;
use Igniter\Pages\Models\Page;
use Igniter\System\Facades\Assets;
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

$manifestPath = getenv('SEED_MANIFEST') ?: ($argv[1] ?? '');
if ($manifestPath === '' || !is_file($manifestPath)) {
    fwrite(STDERR, "SEED_MANIFEST path required\n");
    exit(1);
}

$mode = getenv('SEED_MODE') ?: 'initial';
if (!in_array($mode, ['initial', 'update'], true)) {
    fwrite(STDERR, "SEED_MODE must be initial or update\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
say("Seeding {$manifest['slug']} ({$manifest['name']}) mode={$mode}");

function say(string $msg): void
{
    echo "  {$msg}\n";
}

function settingSet(string $item, mixed $value, string $sort = 'config'): void
{
    $serialized = is_array($value) || is_object($value) ? 1 : null;
    $stored = $serialized ? serialize($value) : (string) $value;

    $exists = DB::table('settings')->where('item', $item)->where('sort', $sort)->exists();
    if ($exists) {
        DB::table('settings')->where('item', $item)->where('sort', $sort)->update([
            'value' => $stored,
            'serialized' => $serialized,
        ]);
        return;
    }

    DB::table('settings')->insert([
        'sort' => $sort,
        'item' => $item,
        'value' => $stored,
        'serialized' => $serialized,
    ]);
}

function saveLocationSettings(Location $location, string $item, array $data): void
{
    $settings = LocationSettings::instance($location, $item);
    foreach ($data as $key => $value) {
        $settings->setAttribute($key, $value);
    }
    $settings->save();
}

function attachMediaFile(object $model, string $sourcePath, string $tag, string $label): void
{
    if (!is_file($sourcePath)) {
        say('Photo skipped (missing '.$sourcePath.')');
        return;
    }
    if (!method_exists($model, 'newMediaInstance')) {
        say('Photo skipped (media API missing)');
        return;
    }

    $model->clearMediaTag($tag);
    try {
        $model->newMediaInstance()->addFromFile($sourcePath, $tag);
    } catch (Throwable $e) {
        say('Photo skipped ('.$label.'): '.$e->getMessage());
        return;
    }
    $model = $model->fresh() ?? $model;

    $media = $model->getMedia($tag)->first();
    if (!$media) {
        say('Photo attach failed for '.$label);
        return;
    }

    $absolute = storage_path('app/public/'.$media->getDiskPath());
    $dir = dirname($absolute);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if (!copy($sourcePath, $absolute)) {
        say('Photo copy failed → '.$absolute);
        return;
    }
    @chmod($absolute, 0666);
    foreach (glob(dirname($absolute).'/thumb_'.$media->getKey().'_*') ?: [] as $thumb) {
        @unlink($thumb);
    }
    say('Photo → '.$label);
}

function attachLocationPhoto(Location $location, string $sourcePath, string $tag = 'thumb'): void
{
    attachMediaFile($location, $sourcePath, $tag, (string) $location->permalink_slug);
}

function applyHomeSlider(string $manifestPath): void
{
    $carousel = dirname($manifestPath).'/carousel.jpg';
    if (!is_file($carousel)) {
        return;
    }
    if (!class_exists(\Igniter\Frontend\Models\Slider::class)) {
        say('Slider skipped (extension missing)');
        return;
    }
    $slider = \Igniter\Frontend\Models\Slider::query()->where('code', 'home-slider')->first();
    if (!$slider) {
        say('Slider skipped (home-slider missing)');
        return;
    }
    attachMediaFile($slider, $carousel, 'images', 'home-slider');
}

function ensureCurrency(string $code, bool $asDefault = false): Currency
{
    $defaults = [
        'USD' => [
            'country_id' => 223,
            'currency_name' => 'US Dollar',
            'currency_symbol' => '$',
            'iso_alpha2' => 'US',
            'iso_alpha3' => 'USA',
            'iso_numeric' => 840,
        ],
        'MXN' => [
            'country_id' => 138,
            'currency_name' => 'Mexican Peso',
            'currency_symbol' => '$',
            'iso_alpha2' => 'MX',
            'iso_alpha3' => 'MEX',
            'iso_numeric' => 484,
        ],
    ];

    $attrs = $defaults[$code] ?? [
        'country_id' => 223,
        'currency_name' => $code,
        'currency_symbol' => '$',
    ];

    $currency = Currency::query()->firstOrCreate(
        ['currency_code' => $code],
        array_merge($attrs, [
            'currency_rate' => 1,
            'symbol_position' => 0,
            'thousand_sign' => ',',
            'decimal_sign' => '.',
            'decimal_position' => 2,
            'currency_status' => 1,
            'is_default' => $asDefault ? 1 : 0,
        ]),
    );

    $currency->currency_status = 1;
    $currency->currency_rate = 1;
    if ($asDefault) {
        Currency::query()->where('currency_code', '!=', $code)->update(['is_default' => 0]);
        $currency->is_default = 1;
    }
    $currency->save();

    return $currency;
}

$defaultCurrencyCode = (string) ($manifest['default_currency'] ?? 'USD');
$neededCurrencies = [$defaultCurrencyCode];
foreach ($manifest['locations'] as $loc) {
    $neededCurrencies[] = (string) $loc['currency'];
}
$neededCurrencies = array_values(array_unique($neededCurrencies));

$currencyIds = [];
foreach ($neededCurrencies as $code) {
    $currency = ensureCurrency($code, $code === $defaultCurrencyCode);
    $currencyIds[$code] = (int) $currency->currency_id;
}
Currency::query()->whereNotIn('currency_code', $neededCurrencies)->update(['currency_status' => 0]);

settingSet('site_name', $manifest['name']);
settingSet('site_email', $manifest['mail']['from_address']);
settingSet('sender_name', $manifest['mail']['from_name']);
settingSet('sender_email', $manifest['mail']['from_address']);
if (!empty($manifest['storefront']['hero_title'])) {
    settingSet('hero_title', (string) $manifest['storefront']['hero_title']);
}
if (!empty($manifest['storefront']['hero_subtitle'])) {
    settingSet('hero_subtitle', (string) $manifest['storefront']['hero_subtitle']);
}
settingSet('protocol', 'smtp');
settingSet('smtp_host', getenv('MAIL_HOST') ?: 'mailpit');
settingSet('smtp_port', getenv('MAIL_PORT') ?: '1025');
settingSet('smtp_encryption', '');
settingSet('smtp_user', '');
settingSet('smtp_pass', '');
settingSet('timezone', $manifest['timezone'] ?? 'UTC');
settingSet('currency_id', (string) $currencyIds[$defaultCurrencyCode]);
settingSet('default_currency', (string) $currencyIds[$defaultCurrencyCode]);
settingSet('distance_unit', ($defaultCurrencyCode === 'MXN') ? 'km' : 'mi');
settingSet('tax_mode', '0');
settingSet('guest_order', '1');
settingSet('allow_registration', '1');
settingSet('detect_language', '0');
settingSet('dilibori_show_language_picker', !empty($manifest['show_language_picker']) ? '1' : '0');

$localeCodes = [];
$defaultLocale = 'en';
foreach ($manifest['locales'] as $locale) {
    $code = (string) $locale['code'];
    $localeCodes[] = $code;
    if (!empty($locale['default'])) {
        $defaultLocale = $code;
    }

    $language = Language::query()->firstOrCreate(
        ['code' => $code],
        [
            'name' => $locale['name'],
            'idiom' => str_starts_with($code, 'es') ? 'spanish' : 'english',
            'status' => 1,
            'can_delete' => 1,
            'is_default' => !empty($locale['default']) ? 1 : 0,
        ],
    );
    $language->name = $locale['name'];
    $language->status = 1;
    $language->is_default = !empty($locale['default']) ? 1 : 0;
    $language->save();
}

Language::query()->whereNotIn('code', $localeCodes)->update(['status' => 0, 'is_default' => 0]);
Language::clearInternalCache();
Language::applySupportedLanguages();
config(['localization.supportedLocales' => $localeCodes]);
say('Locales → '.implode(', ', $localeCodes)." (default {$defaultLocale})");

$locationIds = [];
$keepSlugs = array_map(fn ($loc) => (string) $loc['slug'], $manifest['locations']);
foreach ($manifest['locations'] as $loc) {
    $location = Location::query()
        ->where('permalink_slug', $loc['slug'])
        ->orderByDesc('is_default')
        ->orderBy('location_id')
        ->first();
    if (!$location) {
        $location = Location::query()
            ->where(function ($query) use ($keepSlugs) {
                $query->whereNull('permalink_slug')
                    ->orWhere('permalink_slug', '')
                    ->orWhereNotIn('permalink_slug', $keepSlugs);
            })
            ->when($locationIds !== [], fn ($query) => $query->whereNotIn('location_id', $locationIds))
            ->orderBy('location_id')
            ->first() ?? new Location;
    }
    $description = $loc['description'] ?? [];
    $descValue = $description[$defaultLocale]
        ?? $description['es_MX']
        ?? $description['en']
        ?? '';

    // ti-ext-translate: store per-locale when multiple locales are required
    if (is_array($description) && count($description) > 1) {
        $descValue = $description;
    }

    $location->fill([
        'location_name' => $loc['name'],
        'location_email' => $loc['email'] ?? $manifest['mail']['from_address'],
        'description' => $descValue,
        'location_address_1' => $loc['address_1'] ?? '',
        'location_address_2' => $loc['address_2'] ?? '',
        'location_city' => $loc['city'],
        'location_state' => $loc['state'] ?? '',
        'location_postcode' => $loc['postcode'] ?? '',
        'location_country_id' => (int) $loc['country_id'],
        'location_telephone' => $loc['telephone'] ?? '',
        'location_lat' => $loc['lat'] ?? null,
        'location_lng' => $loc['lng'] ?? null,
        'location_status' => 1,
        'permalink_slug' => $loc['slug'],
        'is_default' => !empty($loc['is_default']) ? 1 : 0,
        'is_auto_lat_lng' => 0,
    ]);
    $location->save();

    $currencyCode = (string) $loc['currency'];
    saveLocationSettings($location, 'options', [
        'currency_id' => $currencyIds[$currencyCode] ?? null,
        'dilibori_currency_code' => $currencyCode,
    ]);

    $photoName = (string) ($loc['photo'] ?? '');
    if ($photoName !== '') {
        attachLocationPhoto($location, dirname($manifestPath).'/'.$photoName);
    }

    $locationIds[] = (int) $location->getKey();
    say("Location #{$location->getKey()}: {$loc['name']} ({$currencyCode})");
}

Location::query()->whereNotIn('permalink_slug', $keepSlugs)->update(['location_status' => 0]);
Location::query()
    ->whereIn('permalink_slug', $keepSlugs)
    ->whereNotIn('location_id', $locationIds)
    ->get()
    ->each(fn (Location $extra) => $extra->delete());

applyHomeSlider($manifestPath);
applyPages($manifest, $defaultLocale);
applyFooterMenuTitles();
applyCodPayment($defaultLocale);
applyStatuses($defaultLocale);
applyMenusPageShowFooter();

$adminEmail = getenv('ADMIN_EMAIL') ?: $manifest['admin']['email'];
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';
$adminName = $manifest['admin']['name'] ?? 'Admin';
$adminUsername = $manifest['admin']['username'] ?? 'admin';
$adminPasswordFile = base_path('storage/dilibori-admin-password');

$admin = User::query()->where('email', $adminEmail)->first()
    ?? User::query()->orderBy('user_id')->first();

if (!$admin) {
    if ($adminPassword === '') {
        $adminPassword = bin2hex(random_bytes(12));
        file_put_contents($adminPasswordFile, $adminPassword);
        chmod($adminPasswordFile, 0600);
        say('Generated admin password → storage/dilibori-admin-password');
    }
    $roleId = UserRole::query()->orderBy('user_role_id')->value('user_role_id');
    $groupId = UserGroup::query()->orderBy('user_group_id')->value('user_group_id');
    $languageId = Language::query()->where('code', $defaultLocale)->value('language_id')
        ?? Language::query()->orderBy('language_id')->value('language_id');
    if (!$roleId || !$groupId) {
        throw new RuntimeException('Cannot create admin: missing user role or group after install');
    }
    AdminAuth::getProvider()->register([
        'email' => $adminEmail,
        'name' => $adminName,
        'language_id' => $languageId,
        'user_role_id' => $roleId,
        'username' => $adminUsername,
        'password' => $adminPassword,
        'super_user' => true,
        'groups' => [$groupId],
        'locations' => $locationIds,
    ], true);
    say("Admin created → {$adminEmail}");
} else {
    $admin->email = $adminEmail;
    // Only reset the password when ADMIN_PASSWORD is explicitly provided.
    // Re-seed without it must not clobber a random provisioned secret.
    if ($adminPassword !== '') {
        $admin->password = $adminPassword;
    }
    if ($admin->isFillable('status') || array_key_exists('status', $admin->getAttributes())) {
        $admin->status = 1;
    }
    $languageId = Language::query()->where('code', $defaultLocale)->value('language_id');
    if ($languageId) {
        $admin->language_id = $languageId;
    }
    $admin->save();
    if (method_exists($admin, 'locations')) {
        $admin->locations()->sync($locationIds);
    }
    say("Admin updated → {$adminEmail}");
}

$required = $manifest['catalog']['require_translations'] ?? $localeCodes;
settingSet('dilibori_required_translations', $required);

$menuSeed = dirname($manifestPath).'/seed-menu.php';
if (is_file($menuSeed)) {
    require $menuSeed;
}

$demoEnabled = !empty($manifest['demo']['enabled']);
if ($demoEnabled && $mode === 'initial') {
    require __DIR__.'/seed-demo-activity.php';
} elseif ($demoEnabled) {
    say('Demo activity skipped (SEED_MODE='.$mode.')');
}

// Theme last so rich catalog seeds cannot leave Orange default CSS cached.
applyTheme($manifest, $manifestPath);

say($demoEnabled && $mode === 'initial'
    ? 'Seed complete (including demo activity)'
    : 'Seed complete (orders/customers untouched)');

function applyTheme(array $manifest, string $manifestPath): void
{
    if (!class_exists(Theme::class)) {
        return;
    }

    $themeCfg = $manifest['theme'] ?? [];
    if ($themeCfg === []) {
        return;
    }

    $theme = Theme::query()->where('code', 'igniter-orange')->first();
    if (!$theme) {
        say('Theme skipped (igniter-orange missing)');
        return;
    }

    $data = $theme->data ?? [];
    if (is_object($data)) {
        $data = (array) $data;
    }

    if (!empty($themeCfg['color_primary'])) {
        $data['color-primary'] = (string) $themeCfg['color_primary'];
    }
    if (!empty($themeCfg['heading_color'])) {
        $data['heading-color'] = (string) $themeCfg['heading_color'];
        settingSet('hero_heading_color', (string) $themeCfg['heading_color']);
    }
    if (!empty($themeCfg['header_accent'])) {
        $data['header-accent'] = (string) $themeCfg['header_accent'];
        settingSet('header_accent', (string) $themeCfg['header_accent']);
        // Cookie bar: black background, white copy (site footer stays brand red).
        $data['gdpr_background_color'] = '#000000';
        $data['gdpr_text_color'] = '#ffffff';
    }
    if (!empty($themeCfg['logo_height'])) {
        $data['logo_height'] = (string) $themeCfg['logo_height'];
    }

    if (!empty($themeCfg['social']) && is_array($themeCfg['social'])) {
        $social = [];
        foreach ($themeCfg['social'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['url'] ?? ''));
            $class = trim((string) ($item['class'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            if ($url === '' || $class === '') {
                continue;
            }
            $social[] = [
                'class' => $class,
                'title' => $title !== '' ? $title : $url,
                'url' => $url,
            ];
        }
        if ($social !== []) {
            $data['social'] = $social;
        }
    }

    $data['enable_gdpr'] = true;

    $logoName = (string) ($themeCfg['logo'] ?? '');
    if ($logoName !== '') {
        $logoFile = dirname($manifestPath).'/'.$logoName;
        if (is_file($logoFile)) {
            $uploads = storage_path('app/public/media/uploads');
            if (!is_dir($uploads)) {
                mkdir($uploads, 0777, true);
            }
            $dest = $uploads.'/'.$logoName;
            if (!copy($logoFile, $dest)) {
                say("Logo copy failed → {$dest}");
            } else {
                @chmod($dest, 0666);
            }
            if (class_exists(MediaLibrary::class)) {
                try {
                    $media = resolve(MediaLibrary::class);
                    $media->put($logoName, (string) file_get_contents($logoFile));
                    $media->resetCache();
                } catch (Throwable $e) {
                    say('Logo media library skipped: '.$e->getMessage());
                }
            }
            if (!is_link(public_path('storage'))) {
                Illuminate\Support\Facades\Artisan::call('storage:link');
            }
            settingSet('site_logo', $logoName);
            $data['logo_image'] = $logoName;
            $data['favicon'] = $logoName;
            say("Logo → {$logoName}");
        } else {
            say("Logo skipped (missing {$logoFile})");
        }
    }

    $theme->data = $data;
    $theme->save();
    Theme::clearThemeInstances();

    try {
        Assets::buildBundles($theme->getTheme());
        clearThemeCombinerCache();
        say('Theme colors applied');
    } catch (Throwable $e) {
        say('Theme CSS rebuild skipped: '.$e->getMessage());
    }
}

function clearThemeCombinerCache(): void
{
    $combiner = storage_path('igniter/combiner');
    if (!is_dir($combiner)) {
        return;
    }
    foreach (glob($combiner.'/*') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function applyPages(array $manifest, string $defaultLocale): void
{
    if (!class_exists(Page::class)) {
        return;
    }

    $pages = $manifest['pages'] ?? [];
    if ($pages === []) {
        return;
    }

    $languageId = Language::query()->where('code', $defaultLocale)->value('language_id');
    // ponytail: Translate returns empty page bodies unless the active locale has a
    // row in igniter_translate_attributes. Write the same copy into `en` and the
    // tenant default. Drop the extra locale once pages stop going through that slot.
    $enLanguageId = Language::query()->where('code', 'en')->value('language_id') ?: $languageId;

    foreach ($pages as $slug => $pageDef) {
        $page = Page::query()->where('permalink_slug', $slug)->first();
        if (!$page) {
            say("Page skipped (missing slug {$slug})");
            continue;
        }

        $title = (string) $pageDef['title'];
        $content = (string) $pageDef['content'];
        $meta = (string) ($pageDef['meta_description'] ?? '');

        if ($enLanguageId) {
            $page->language_id = (int) $enLanguageId;
        }
        $page->title = $title;
        $page->content = $content;
        if ($meta !== '') {
            $page->meta_description = $meta;
        }
        $page->status = 1;
        $page->save();

        if ($page->methodExists('setAttributeTranslatedValue')) {
            foreach (array_unique(['en', $defaultLocale]) as $locale) {
                $page->setAttributeTranslatedValue('title', $title, $locale);
                $page->setAttributeTranslatedValue('content', $content, $locale);
                if ($meta !== '') {
                    $page->setAttributeTranslatedValue('meta_description', $meta, $locale);
                }
            }
            $page->save();
        }
        say("Page → {$slug}");
    }
}

function applyFooterMenuTitles(): void
{
    if (!Schema::hasTable('igniter_pages_menu_items')) {
        return;
    }

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
}

/**
 * Checkout renders payments.name / payments.description from the DB, not lang files.
 * Match the tenant default locale so COD is not stuck on Igniter's vendor English.
 */
function applyCodPayment(string $defaultLocale): void
{
    if (!Schema::hasTable('payments')) {
        return;
    }

    $spanish = str_starts_with($defaultLocale, 'es');
    $name = $spanish ? 'Pago contra entrega' : 'Cash on delivery';
    $description = $spanish
        ? 'Paga en efectivo al recoger tu pedido o cuando te lo entreguen.'
        : 'Pay with cash when you pick up your order or when it is delivered.';

    $updated = DB::table('payments')->where('code', 'cod')->update([
        'name' => $name,
        'description' => $description,
    ]);
    if ($updated) {
        say("Payment COD → {$name}");
    }
}

/**
 * Status names and comments live in the statuses table, not lang files.
 * Match the tenant default locale so admin and customer-facing status text
 * are not stuck on Igniter's vendor English.
 */
function applyStatuses(string $defaultLocale): void
{
    if (!Schema::hasTable('statuses')) {
        return;
    }

    if (!str_starts_with($defaultLocale, 'es')) {
        return;
    }

    $rows = [
        ['status_id' => 1, 'status_for' => 'order', 'status_name' => 'Recibido', 'status_comment' => 'Tu pedido ha sido recibido.'],
        ['status_id' => 2, 'status_for' => 'order', 'status_name' => 'Pendiente', 'status_comment' => 'Tu pedido está pendiente'],
        ['status_id' => 3, 'status_for' => 'order', 'status_name' => 'Preparación', 'status_comment' => 'Tu pedido está en la cocina'],
        ['status_id' => 4, 'status_for' => 'order', 'status_name' => 'En camino', 'status_comment' => 'Tu pedido llegará pronto.'],
        ['status_id' => 5, 'status_for' => 'order', 'status_name' => 'Completado', 'status_comment' => ''],
        ['status_id' => 6, 'status_for' => 'reservation', 'status_name' => 'Confirmada', 'status_comment' => 'Tu reserva de mesa ha sido confirmada.'],
        ['status_id' => 7, 'status_for' => 'reservation', 'status_name' => 'Cancelada', 'status_comment' => 'Tu reserva de mesa ha sido cancelada.'],
        ['status_id' => 8, 'status_for' => 'reservation', 'status_name' => 'Pendiente', 'status_comment' => 'Tu reserva de mesa está pendiente.'],
        ['status_id' => 9, 'status_for' => 'order', 'status_name' => 'Cancelado', 'status_comment' => ''],
    ];

    $updated = 0;
    foreach ($rows as $row) {
        $count = DB::table('statuses')
            ->where('status_id', $row['status_id'])
            ->where('status_for', $row['status_for'])
            ->update([
                'status_name' => $row['status_name'],
                'status_comment' => $row['status_comment'],
            ]);
        $updated += $count;
    }
    if ($updated) {
        say("Statuses → es_MX ({$updated} rows)");
    }
}

/**
 * Orange theme menus page defaults to hideFooter. Show the site footer on
 * menus for every tenant (About / Privacy / social / newsletter).
 */
function applyMenusPageShowFooter(): void
{
    $target = base_path(
        'vendor/tastyigniter/ti-theme-orange/resources/views/_pages/local/menus.blade.php'
    );
    $override = __DIR__.'/menus-page.blade.php';
    if (!is_file($override)) {
        $override = '/opt/platform/seeds/common/menus-page.blade.php';
    }
    if (!is_file($target) || !is_file($override) || !is_writable($target)) {
        say('Menus page footer skipped (missing or not writable)');

        return;
    }

    copy($override, $target);
    foreach (glob(storage_path('igniter/cache/*/*/menus.blade.php')) ?: [] as $cached) {
        @unlink($cached);
    }
    say('Menus page → site footer enabled');
}
