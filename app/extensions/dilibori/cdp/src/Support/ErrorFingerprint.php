<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Support;

use Igniter\Flame\Exception\AjaxException;
use Igniter\Flame\Exception\ApplicationException;

/**
 * Fingerprints for Application Error so audiences can select “everyone who hit bug X”.
 */
class ErrorFingerprint
{
    public static function fromThrowable(\Throwable $e): string
    {
        $class = $e::class;
        $file = $e->getFile();
        $line = (string) $e->getLine();

        // UX failures share a throw site; include the message so “Horchata OOS” ≠ “closed”.
        $message = '';
        if ($e instanceof ApplicationException || $e instanceof AjaxException) {
            $message = '|'.mb_substr($e->getMessage(), 0, 200);
        }

        return 'php_'.substr(hash('sha256', "{$class}|{$file}|{$line}{$message}"), 0, 16);
    }

    public static function fromJs(string $message, string $source, int $line): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($message)) ?? $message;

        return 'js_'.substr(hash('sha256', "{$normalized}|{$source}|{$line}"), 0, 16);
    }
}
