<?php

/**
 * Pizza Rin flyer catalog. Safe to re-run: wipes menus/categories/options and
 * order line rows, then seeds both locations from menu.json.
 *
 * Called from seed-from-manifest.php when this file sits next to the manifest.
 * Can also be run alone: php seed-menu.php /path/to/menu.json
 */

declare(strict_types=1);

use Igniter\Cart\Models\Category;
use Igniter\Cart\Models\Menu;
use Igniter\Cart\Models\MenuOption;
use Igniter\Local\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (!class_exists(DB::class) && is_file('/var/www/html/vendor/autoload.php')) {
    require '/var/www/html/vendor/autoload.php';
    $app = require '/var/www/html/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}

if (!function_exists('say')) {
    function say(string $msg): void
    {
        echo "  {$msg}\n";
    }
}

$menuJsonPath = dirname(__FILE__).'/menu.json';
// Only honor CLI argv when this file is the entrypoint. When required from
// seed-from-manifest.php, $argv[1] is the manifest path and must not win.
if (isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)
    && isset($argv[1]) && is_string($argv[1]) && $argv[1] !== '' && is_file($argv[1])) {
    $menuJsonPath = $argv[1];
}

if (!is_file($menuJsonPath)) {
    throw new RuntimeException("Missing menu catalog: {$menuJsonPath}");
}

$catalog = json_decode((string) file_get_contents($menuJsonPath), true, 512, JSON_THROW_ON_ERROR);

/** Same Spanish string in en + es_MX so Translate does not blank titles. */
function es(string $text): array
{
    return ['en' => $text, 'es_MX' => $text];
}

function attachMenuPhoto(Menu $menu, string $sourcePath): void
{
    if (!is_file($sourcePath)) {
        say('Photo skipped (missing '.$sourcePath.')');
        return;
    }
    if (!method_exists($menu, 'newMediaInstance')) {
        say('Photo skipped (media API missing)');
        return;
    }

    $menu->clearMediaTag('thumb');
    try {
        $menu->newMediaInstance()->addFromFile($sourcePath, 'thumb');
    } catch (Throwable $e) {
        say('Photo skipped ('.$menu->getKey().'): '.$e->getMessage());
        return;
    }
    $menu = $menu->fresh() ?? $menu;
    $media = $menu->getMedia('thumb')->first();
    if (!$media) {
        return;
    }

    $absolute = storage_path('app/public/'.$media->getDiskPath());
    $dir = dirname($absolute);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    if (!copy($sourcePath, $absolute)) {
        return;
    }
    @chmod($absolute, 0666);
    foreach (glob(dirname($absolute).'/thumb_'.$media->getKey().'_*') ?: [] as $thumb) {
        @unlink($thumb);
    }
}

say('Seeding Pizza Rin menu catalog');

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
    'menus',
    'categories',
    'stocks',
    'stock_history',
] as $table) {
    if (Schema::hasTable($table)) {
        DB::table($table)->truncate();
    }
}
if (Schema::hasTable('media_attachments')) {
    DB::table('media_attachments')->where('attachment_type', 'menus')->delete();
}
if (Schema::hasTable('locationables')) {
    DB::table('locationables')
        ->whereIn('locationable_type', ['menus', 'categories', 'menu_options'])
        ->delete();
}
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$locations = Location::query()
    ->where('location_status', 1)
    ->orderBy('location_id')
    ->get();
if ($locations->isEmpty()) {
    throw new RuntimeException('No active locations to attach menu to');
}
$locationIds = $locations->map(fn ($loc) => (int) $loc->getKey())->all();

$categoryIds = [];
foreach ($catalog['categories'] as $cat) {
    $category = Category::create([
        'name' => es((string) $cat['name']),
        'permalink_slug' => (string) $cat['slug'],
        'description' => es(''),
        'priority' => (int) $cat['priority'],
        'status' => 1,
    ]);
    $category->locations()->sync($locationIds);
    if ($category->methodExists('setAttributeTranslatedValue')) {
        foreach (['en', 'es_MX'] as $locale) {
            $category->setAttributeTranslatedValue('name', (string) $cat['name'], $locale);
        }
        $category->save();
    }
    $categoryIds[(string) $cat['slug']] = $category->getKey();
}
if (method_exists(Category::class, 'fixTree')) {
    Category::fixTree();
}
say('Categories: '.implode(', ', array_keys($categoryIds)));

foreach ($locations as $location) {
    $locationId = (int) $location->getKey();
    $opts = [];

    $optionPriority = 0;
    foreach ($catalog['option_groups'] as $groupKey => $group) {
        $optionPriority++;
        $option = MenuOption::create([
            'option_name' => es((string) $group['name']),
            'display_type' => (string) $group['display_type'],
            'priority' => $optionPriority,
        ]);
        $valuePrices = [];
        foreach ($group['values'] as $priority => $value) {
            $valuePrice = (float) ($value['price'] ?? 0);
            $optionValue = $option->option_values()->create([
                'name' => es((string) $value['name']),
                'price' => $valuePrice,
                'priority' => $priority + 1,
            ]);
            if ($optionValue->methodExists('setAttributeTranslatedValue')) {
                foreach (['en', 'es_MX'] as $locale) {
                    $optionValue->setAttributeTranslatedValue(
                        'name',
                        (string) $value['name'],
                        $locale,
                    );
                }
                $optionValue->save();
            }
            $valuePrices[(string) $value['key']] = $valuePrice;
        }
        $option->locations()->sync([$locationId]);
        $option->load('option_values');
        if ($option->methodExists('setAttributeTranslatedValue')) {
            foreach (['en', 'es_MX'] as $locale) {
                $option->setAttributeTranslatedValue('option_name', (string) $group['name'], $locale);
            }
            $option->save();
        }
        $opts[$groupKey] = [
            'option' => $option,
            'required' => !empty($group['required']),
            'display_type' => (string) $group['display_type'],
            'value_keys' => array_column($group['values'], 'key'),
            'value_prices' => $valuePrices,
        ];
    }

    foreach ($catalog['items'] as $item) {
        $groupKeys = $item['option_groups'] ?? [];
        if ($groupKeys === [] && isset($item['option_group'])) {
            $groupKeys = [(string) $item['option_group']];
        }
        $prices = $item['prices'] ?? null;
        $basePrice = isset($item['price'])
            ? (float) $item['price']
            : (float) reset($prices);

        $menu = Menu::create([
            'menu_name' => es((string) $item['name']),
            'menu_description' => es((string) ($item['description'] ?? '')),
            'menu_price' => $basePrice,
            'minimum_qty' => 1,
            'menu_status' => 1,
        ]);
        $menu->categories()->sync([$categoryIds[(string) $item['category']]]);
        $menu->locations()->sync([$locationId]);

        if ($menu->methodExists('setAttributeTranslatedValue')) {
            foreach (['en', 'es_MX'] as $locale) {
                $menu->setAttributeTranslatedValue('menu_name', (string) $item['name'], $locale);
                if (!empty($item['description'])) {
                    $menu->setAttributeTranslatedValue('menu_description', (string) $item['description'], $locale);
                }
            }
            $menu->save();
        }

        $photoRel = (string) ($item['photo'] ?? '');
        if ($photoRel !== '') {
            attachMenuPhoto($menu, dirname($menuJsonPath).'/'.$photoRel);
        }

        foreach ($groupKeys as $priority => $groupKey) {
            if (!isset($opts[$groupKey])) {
                continue;
            }
            $opt = $opts[$groupKey];
            $option = $opt['option'];
            $required = $opt['required'] ? 1 : 0;
            $isRadio = $opt['display_type'] === 'radio';
            $menuOption = $menu->menu_options()->create([
                'option_id' => $option->getKey(),
                'is_required' => $required,
                'priority' => $priority + 1,
                'min_selected' => $required,
                'max_selected' => $isRadio ? $required : 0,
            ]);

            $valueKeys = $opt['value_keys'];
            foreach ($option->option_values as $index => $optionValue) {
                $key = $valueKeys[$index] ?? null;
                if ($isRadio && is_array($prices)) {
                    $absolute = $key !== null && isset($prices[$key]) ? (float) $prices[$key] : $basePrice;
                    $override = $absolute - $basePrice;
                } else {
                    $override = $key !== null
                        ? (float) ($opt['value_prices'][$key] ?? $optionValue->price)
                        : (float) $optionValue->price;
                }
                $menuOption->menu_option_values()->create([
                    'option_value_id' => $optionValue->getKey(),
                    'override_price' => $override,
                    'priority' => $optionValue->priority,
                ]);
            }
        }

        say("Menu [{$location->location_name}]: {$item['name']} (\${$basePrice})");
    }
}

say('Pizza Rin menu seed complete');

$menusPage = base_path('vendor/tastyigniter/ti-theme-orange/resources/views/_pages/local/menus.blade.php');
$menusPageOverride = dirname(__FILE__).'/menus-page.blade.php';
if (is_file($menusPage) && is_file($menusPageOverride) && is_writable($menusPage)) {
    copy($menusPageOverride, $menusPage);
    foreach (glob(storage_path('igniter/cache/*/*/menus.blade.php')) ?: [] as $cached) {
        @unlink($cached);
    }
    say('Menus page → showThumb enabled');
}
