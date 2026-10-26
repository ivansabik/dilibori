#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Copy seed originals back onto attachment paths after a container recreate.
 * Storage is not a volume, so thumbs fall back to placeholders until the file returns.
 */

use Igniter\Flame\Database\Attach\Media;
use Illuminate\Support\Facades\DB;

ob_implicit_flush(true);

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$seedImages = '/opt/seed/images';
if (!is_dir($seedImages)) {
    fwrite(STDERR, "restore-media: no seed images at {$seedImages}\n");
    exit(0);
}

$restored = 0;

if (class_exists(Media::class)) {
    foreach (Media::query()->cursor() as $media) {
        $sourceName = basename((string) $media->file_name);
        if ($sourceName === '' || $sourceName === '.' || $sourceName === '..') {
            continue;
        }
        $source = $seedImages.'/'.$sourceName;
        $dest = $media->getFullDiskPath();
        if (is_file($dest) || !is_file($source)) {
            continue;
        }
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            fwrite(STDERR, "restore-media: cannot mkdir {$dir}\n");
            continue;
        }
        if (!copy($source, $dest)) {
            fwrite(STDERR, "restore-media: copy failed {$dest}\n");
            continue;
        }
        chmod($dir, 0775);
        chmod($dest, 0664);
        chown($dir, 'www-data');
        chgrp($dir, 'www-data');
        chown($dest, 'www-data');
        chgrp($dest, 'www-data');
        foreach (glob($dir.'/thumb_'.$media->getKey().'_*') ?: [] as $thumb) {
            unlink($thumb);
        }
        $restored++;
    }
}

$logoName = DB::table('settings')->where('item', 'site_logo')->value('value');
if (is_string($logoName) && $logoName !== '') {
    $logoName = basename($logoName);
    $logoSource = $seedImages.'/'.$logoName;
    $logoDest = storage_path('app/public/media/uploads/'.$logoName);
    if (!is_file($logoDest) && is_file($logoSource)) {
        $logoDir = dirname($logoDest);
        if (!is_dir($logoDir)) {
            mkdir($logoDir, 0775, true);
        }
        if (copy($logoSource, $logoDest)) {
            chmod($logoDir, 0775);
            chmod($logoDest, 0664);
            chown($logoDir, 'www-data');
            chgrp($logoDir, 'www-data');
            chown($logoDest, 'www-data');
            chgrp($logoDest, 'www-data');
            $restored++;
        }
    }
}

echo "restore-media: restored {$restored}\n";
