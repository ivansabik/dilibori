<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Support;

/**
 * Fingerprints for Application Error so audiences can select “everyone who hit bug X”.
 */
final class ErrorFingerprint
{
    public static function fromThrowable(\Throwable $e): string
    {
        $class = $e::class;
        $file = $e->getFile();
        $line = (string) $e->getLine();

        return 'php_'.substr(hash('sha256', $class.'|'.$file.'|'.$line), 0, 16);
    }

    public static function fromJs(string $message, string $source, int $line): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($message)) ?? $message;

        return 'js_'.substr(hash('sha256', $normalized.'|'.$source.'|'.$line), 0, 16);
    }
}
