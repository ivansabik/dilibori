<?php

declare(strict_types=1);

/**
 * One assert: data URIs must stay intact; http(s) paths strip to path-only.
 */
require_once dirname(__DIR__).'/src/helpers.php';

$blank = 'data:image/png;base64,abc';
assert(orange_media_src($blank) === $blank, 'data URI must not be stripped');

$abs = 'http://pizza-rin.prod.localhost:8088/storage/media/x.jpg';
assert(orange_media_src($abs) === '/storage/media/x.jpg', 'http URL must become path');

echo "OK: orange_media_src\n";
