<?php

declare(strict_types=1);

/**
 * Fail if es_MX lang coverage slips vs vendor English keys,
 * or if locale override values still contain product brand tokens.
 *
 * Run in a tenant container:
 *   php extensions/taconazo/locale/tests/lang_coverage_check.php
 */

function lang_coverage_flat(array $array, string $prefix = ''): array
{
    $out = [];
    foreach ($array as $key => $value) {
        $dotted = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        if (is_array($value)) {
            $out += lang_coverage_flat($value, $dotted);
        } else {
            $out[$dotted] = (string) $value;
        }
    }

    return $out;
}

function lang_coverage_placeholders(string $value): array
{
    preg_match_all('/%(?:\d+\$)?[sd]|:\w+|\{[\w.]+\}/', $value, $matches);

    sort($matches[0]);

    return $matches[0];
}

$vendorRoot = getenv('TI_VENDOR_ROOT') ?: '';
if ($vendorRoot === '' || !is_dir($vendorRoot)) {
    $candidates = [
        dirname(__DIR__, 5).'/vendor/tastyigniter',
        '/var/www/html/vendor/tastyigniter',
    ];
    foreach ($candidates as $candidate) {
        if (is_dir($candidate)) {
            $vendorRoot = $candidate;
            break;
        }
    }
}
assert($vendorRoot !== '' && is_dir($vendorRoot), 'vendor/tastyigniter not found; set TI_VENDOR_ROOT');
$esRoot = dirname(__DIR__).'/lang/es_MX/igniter';

$map = [
    'core/resources/lang/en/admin.php' => 'admin.php',
    'core/resources/lang/en/main.php' => 'main.php',
    'core/resources/lang/en/system.php' => 'system.php',
    'ti-ext-api/resources/lang/en/default.php' => 'api/default.php',
    'ti-ext-automation/resources/lang/en/default.php' => 'automation/default.php',
    'ti-ext-broadcast/resources/lang/en/default.php' => 'broadcast/default.php',
    'ti-ext-cart/resources/lang/en/default.php' => 'cart/default.php',
    'ti-ext-coupons/resources/lang/en/default.php' => 'coupons/default.php',
    'ti-ext-frontend/resources/lang/en/default.php' => 'frontend/default.php',
    'ti-ext-local/resources/lang/en/default.php' => 'local/default.php',
    'ti-ext-pages/resources/lang/en/default.php' => 'pages/default.php',
    'ti-ext-payregister/resources/lang/en/default.php' => 'payregister/default.php',
    'ti-ext-reservation/resources/lang/en/default.php' => 'reservation/default.php',
    'ti-ext-socialite/resources/lang/en/default.php' => 'socialite/default.php',
    'ti-ext-user/resources/lang/en/default.php' => 'user/default.php',
    'ti-theme-orange/resources/lang/en/default.php' => 'orange/default.php',
];

$failures = 0;
$warnings = 0;

foreach ($map as $enRel => $esRel) {
    $enPath = "{$vendorRoot}/{$enRel}";
    $esPath = "{$esRoot}/{$esRel}";
    assert(is_file($enPath), "English source missing: {$enPath}");
    assert(is_file($esPath), "es_MX override missing: {$esPath}");

    $en = lang_coverage_flat(include $enPath);
    $es = lang_coverage_flat(include $esPath);

    $missing = array_diff_key($en, $es);
    foreach ($missing as $key => $_) {
        fwrite(STDERR, "MISSING {$esRel}: {$key}\n");
        $failures++;
    }

    foreach ($en as $key => $enValue) {
        if (!isset($es[$key])) {
            continue;
        }
        $esValue = $es[$key];
        if (lang_coverage_placeholders($enValue) !== lang_coverage_placeholders($esValue)) {
            fwrite(STDERR, "PLACEHOLDER {$esRel}: {$key}\n");
            $failures++;
        }
        // Ignore brand tokens that only appear inside URLs (e.g. tastyigniter.com docs).
        $brandHaystack = preg_replace('#https?://[^\s"\'<>]+#i', '', $esValue) ?? $esValue;
        if (preg_match('/TastyIgniter|\bPorton\b/i', $brandHaystack)) {
            fwrite(STDERR, "BRAND {$esRel}: {$key}\n");
            $failures++;
        }
        if ($enValue === $esValue && preg_match('/[A-Za-z]{4}/', strip_tags($esValue))) {
            fwrite(STDOUT, "WARN same-as-en {$esRel}: {$key}\n");
            $warnings++;
        }
    }
}

function lang_coverage_scan_brand(string $localeRoot, string $label): int
{
    $brandFailures = 0;
    if (!is_dir($localeRoot)) {
        return 0;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($localeRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $rel = substr($file->getPathname(), strlen($localeRoot) + 1);
        $lines = lang_coverage_flat(include $file->getPathname());
        foreach ($lines as $key => $value) {
            $brandHaystack = preg_replace('#https?://[^\s"\'<>]+#i', '', $value) ?? $value;
            if (preg_match('/TastyIgniter|\bPorton\b/i', $brandHaystack)) {
                fwrite(STDERR, "BRAND {$label}/{$rel}: {$key}\n");
                $brandFailures++;
            }
        }
    }

    return $brandFailures;
}

$langRoot = dirname(__DIR__).'/lang';
$failures += lang_coverage_scan_brand("{$langRoot}/en/igniter", 'en');
$failures += lang_coverage_scan_brand("{$langRoot}/es_MX/igniter", 'es_MX');

assert($failures === 0, "lang coverage failed with {$failures} error(s)");
echo "OK: es_MX lang coverage ({$warnings} same-as-en warnings); en/es_MX brand scan clean\n";
