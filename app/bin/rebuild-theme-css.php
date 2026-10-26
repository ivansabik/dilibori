#!/usr/bin/env php
<?php

/**
 * Rebuild Orange theme CSS from DB customizations into this container's public/.
 *
 * Platform tenants mount storage/ but not public/vendor/igniter-orange/css/.
 * Provision builds black (or brand) CSS into a one-off container; the runtime
 * image still has stock #ff4900 until this runs. Call on every runtime boot
 * after dilibori-installed.flag exists.
 */

declare(strict_types=1);

use Igniter\Main\Models\Theme;
use Igniter\System\Facades\Assets;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

// Entrypoint captures stdout; flush so "rebuilt" appears before Apache starts.
ob_implicit_flush(true);

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (!class_exists(Theme::class)) {
    fwrite(STDERR, "rebuild-theme-css: Theme model unavailable\n");
    exit(0);
}

$theme = Theme::query()->where('code', 'igniter-orange')->first();
if (!$theme) {
    fwrite(STDERR, "rebuild-theme-css: igniter-orange missing\n");
    exit(0);
}

$data = $theme->data ?? [];
if (is_object($data)) {
    $data = (array) $data;
}
$primary = (string) ($data['color-primary'] ?? '');

try {
    Assets::buildBundles($theme->getTheme());

    $combiner = storage_path('igniter/combiner');
    if (is_dir($combiner)) {
        File::cleanDirectory($combiner);
    }

    // Combiner also caches forever keys (ti.combiner.*) in the app cache.
    Artisan::call('cache:clear');

    echo 'rebuild-theme-css: rebuilt igniter-orange'
        .($primary !== '' ? " (color-primary={$primary})" : '')
        ."\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'rebuild-theme-css: '.$e->getMessage()."\n");
    exit(1);
}
