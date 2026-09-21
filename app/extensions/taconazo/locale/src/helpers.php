<?php

declare(strict_types=1);

if (!function_exists('orange_media_src')) {
    /**
     * Path-only media URLs for same-host assets. Keep data: placeholders intact —
     * parse_url(PHP_URL_PATH) strips the scheme and breaks blank thumbs.
     */
    function orange_media_src(string $url): string
    {
        if ($url === '' || str_starts_with($url, 'data:')) {
            return $url;
        }

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }
}
