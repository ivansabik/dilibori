<?php

declare(strict_types=1);

namespace Dilibori\Cdp;

use Dilibori\Cdp\Contracts\EventClient;
use Dilibori\Cdp\Http\Middleware\InjectAnalyticsSnippet;
use Dilibori\Cdp\Listeners\RegisterAnalyticsListeners;
use Dilibori\Cdp\Support\Analytics;
use Dilibori\Cdp\Support\ErrorFingerprint;
use Dilibori\Cdp\Support\EventClientFactory;
use Dilibori\Cdp\Support\Events;
use Dilibori\Cdp\Support\Snippet;
use Igniter\Admin\Facades\Template;
use Igniter\Flame\Support\Facades\Igniter;
use Igniter\System\Classes\BaseExtension;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Override;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Extension extends BaseExtension
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cdp.php', 'dilibori-cdp');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'dilibori.cdp');

        $this->app->singleton(EventClient::class, fn (): EventClient => EventClientFactory::make());
    }

    #[Override]
    public function boot(): void
    {
        if (! config('dilibori-cdp.enabled', true)) {
            return;
        }

        RegisterAnalyticsListeners::register();
        $this->registerErrorReporter();

        if (Igniter::runningInAdmin()) {
            return;
        }

        Template::registerHook('endBody', fn (): View => Snippet::view());

        $this->app['router']->pushMiddlewareToGroup('web', InjectAnalyticsSnippet::class);
        $this->app['router']->pushMiddlewareToGroup('igniter', InjectAnalyticsSnippet::class);
    }

    private function registerErrorReporter(): void
    {
        $this->app->afterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $e): void {
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
            });
        });
    }
}
