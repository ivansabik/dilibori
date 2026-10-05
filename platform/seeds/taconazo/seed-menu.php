<?php

/**
 * Run the rich El Taconazo catalog (menus, photos, pages, dining layout).
 * Demo orders/customers/coupons come from common/seed-demo-activity.php.
 * Catalog + images live under /opt/seed (app/seed mounted by the runtime agent).
 */
declare(strict_types=1);

$richSeed = '/opt/seed/el-taconazo.php';
if (!is_file($richSeed)) {
    say('Rich taconazo seed skipped (missing '.$richSeed.')');
    return;
}

say('Running rich El Taconazo seed…');
passthru('php '.escapeshellarg($richSeed), $exitCode);
if ($exitCode !== 0) {
    throw new RuntimeException("el-taconazo.php exited {$exitCode}");
}
