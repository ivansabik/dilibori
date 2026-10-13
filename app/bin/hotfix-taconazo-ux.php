<?php

declare(strict_types=1);

/**
 * One-shot hot-fix for El Taconazo UX refresh (theme, pages, media, settings).
 * Run inside a tenant container: php /path/to/hotfix-taconazo-ux.php
 */

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Igniter\Cart\Models\Menu;
use Igniter\Frontend\Models\Slider;
use Igniter\Main\Models\Theme;
use Igniter\System\Facades\Assets;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

function say(string $msg): void
{
    echo $msg.PHP_EOL;
}

function attachPhoto(object $model, string $path, string $tag, bool $clear = true): void
{
    if (! is_file($path)) {
        say("missing {$path}");

        return;
    }
    if ($clear) {
        $model->clearMediaTag($tag);
    }
    $model->newMediaInstance()->addFromFile($path, $tag);
    $model = $model->fresh() ?? $model;
    $media = $model->getMedia($tag)->last();
    if (! $media) {
        say('attach fail');

        return;
    }
    $absolute = storage_path('app/public/'.$media->getDiskPath());
    if (! is_dir(dirname($absolute))) {
        mkdir(dirname($absolute), 0777, true);
    }
    copy($path, $absolute);
    @chmod($absolute, 0666);
    foreach (glob(dirname($absolute).'/thumb_'.$media->getKey().'_*') ?: [] as $thumb) {
        @unlink($thumb);
    }
}

$from = base_path('extensions/taconazo/locale/resources/views/orange/_pages');
$to = base_path('vendor/tastyigniter/ti-theme-orange/resources/views/_pages');
if (is_dir($from) && is_dir($to)) {
    foreach (File::allFiles($from) as $file) {
        $dest = $to.DIRECTORY_SEPARATOR.$file->getRelativePathname();
        File::ensureDirectoryExists(dirname($dest));
        File::copy($file->getPathname(), $dest);
    }
    say('pages published');
}

$theme = Theme::query()->where('code', 'igniter-orange')->first();
if ($theme) {
    $data = (array) ($theme->data ?? []);
    $data['color-primary'] = '#C43C2C';
    $data['heading-color'] = '#111111';
    $data['header-accent'] = '#C43C2C';
    $theme->data = $data;
    $theme->save();
    Theme::clearThemeInstances();
    Assets::buildBundles($theme->getTheme());
    $combiner = storage_path('igniter/combiner');
    if (is_dir($combiner)) {
        File::cleanDirectory($combiner);
    }
    say('theme rebuilt');
}

$pairs = [
    'header_accent' => '#C43C2C',
    'hero_heading_color' => '#111111',
    'hero_title' => 'Trompo-carved pastor. Pickup or delivery.',
    'hero_subtitle' => 'El Taconazo · El Paso & Ciudad Juárez',
    'hero_title_en' => 'Trompo-carved pastor. Pickup or delivery.',
    'hero_subtitle_en' => 'El Taconazo · El Paso & Ciudad Juárez',
    'hero_title_es_MX' => 'Pastor del trompo. Para recoger o a domicilio.',
    'hero_subtitle_es_MX' => 'El Taconazo · El Paso y Ciudad Juárez',
];
foreach ($pairs as $item => $value) {
    DB::table('settings')->updateOrInsert(
        ['item' => $item, 'sort' => 'config'],
        ['value' => $value, 'serialized' => null]
    );
}
say('settings updated');

$map = [
    'Horchata' => '/opt/seed/images/horchata.jpg',
    'Jamaica' => '/opt/seed/images/jamaica.jpg',
    'Mexican Coke' => '/opt/seed/images/mexican-coke.jpg',
    'Coca mexicana' => '/opt/seed/images/mexican-coke.jpg',
    'Guacamole' => '/opt/seed/images/guacamole-chips.jpg',
    'Grilled onions' => '/opt/seed/images/cebollitas.jpg',
    'Cebollitas' => '/opt/seed/images/cebollitas.jpg',
    'Esquites' => '/opt/seed/images/esquites.jpg',
];
foreach (Menu::query()->where('menu_status', 1)->get() as $menu) {
    foreach ($map as $needle => $path) {
        if (stripos((string) $menu->menu_name, $needle) === false) {
            continue;
        }
        if ($menu->getMedia('thumb')->count() === 0) {
            attachPhoto($menu, $path, 'thumb', true);
            say('photo '.$menu->menu_name);
        }
        break;
    }
}

$slider = Slider::query()->where('code', 'home-slider')->first();
if ($slider) {
    $slider->clearMediaTag('images');
    $first = true;
    foreach ([
        ['/opt/seed/images/plato-taconazo.jpg', 'plato'],
        ['/opt/seed/images/taco-al-pastor.jpg', 'pastor'],
        ['/opt/seed/images/el-taconazo.png', 'store'],
    ] as [$path, $label]) {
        if (! is_file($path)) {
            continue;
        }
        attachPhoto($slider, $path, 'images', $first);
        $first = false;
        say('slide '.$label);
    }
}

$favoritoIds = Menu::query()
    ->where('menu_status', 1)
    ->where(function ($query) {
        $query->where('menu_name', 'like', '%Plato Taconazo%')
            ->orWhere('menu_name', 'like', '%mix%')
            ->orWhere('menu_name', 'like', '%Pastor%');
    })
    ->orderBy('menu_priority')
    ->limit(3)
    ->pluck('menu_id')
    ->all();
DB::table('settings')->updateOrInsert(
    ['item' => 'taconazo_favoritos_ids', 'sort' => 'config'],
    ['value' => implode(',', $favoritoIds), 'serialized' => null]
);
say('favoritos='.implode(',', $favoritoIds));

Cache::flush();
if (method_exists(\Igniter\System\Models\Settings::class, 'clearInternalCache')) {
    \Igniter\System\Models\Settings::clearInternalCache();
}
if (is_dir(storage_path('igniter/cache'))) {
    File::cleanDirectory(storage_path('igniter/cache'));
}
Artisan::call('view:clear');
Artisan::call('cache:clear');
say('done');
