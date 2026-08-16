<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Support;

use Igniter\Flame\Support\Facades\Igniter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Emits Application Error for storefront failures (PHP).
 */
final class ReportApplicationError
{
    public static function fromThrowable(Throwable $e): void
    {
        if (Igniter::runningInAdmin()) {
            return;
        }

        if ($e instanceof NotFoundHttpException || $e instanceof ValidationException) {
            return;
        }

        try {
            $file = $e->getFile();
            $message = mb_substr($e->getMessage(), 0, 500);

            Analytics::track(Events::APPLICATION_ERROR, array_filter([
                'error_id' => ErrorFingerprint::fromThrowable($e),
                'error_source' => 'php',
                'error_type' => $e::class,
                'error_message' => $message,
                'error_file' => $file,
                'error_line' => $e->getLine(),
                'path' => request()->path(),
                'url' => request()->fullUrl(),
                'status_code' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : null,
            ], static fn ($v) => $v !== null && $v !== ''));
        } catch (Throwable) {
            // Never break exception reporting.
        }
    }
}
