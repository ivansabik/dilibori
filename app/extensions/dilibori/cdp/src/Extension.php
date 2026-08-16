<?php

declare(strict_types=1);

namespace Dilibori\Cdp;

use Dilibori\Cdp\Contracts\EventClient;
use Dilibori\Cdp\Http\Middleware\InjectAnalyticsSnippet;
use Dilibori\Cdp\Listeners\RegisterAnalyticsListeners;
use Dilibori\Cdp\Support\EventClientFactory;
use Dilibori\Cdp\Support\ReportApplicationError;
use Dilibori\Cdp\Support\Snippet;
use Igniter\Admin\Facades\Template;
use Igniter\Flame\Support\Facades\Igniter;
use Igniter\System\Classes\BaseExtension;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Event;
use Override;
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
        // Igniter's reportable runs first and returns false for ApplicationException /
        // AjaxException (dontReport), which stops Laravel's reportable chain. Always
        // emit from exception.beforeReport (dispatched inside Igniter's handler).
        // Also covers UnexpectedValueException when laravel.log is unwritable —
        // Laravel may fail while logging before later reportables finish cleanly.
        Event::listen('exception.beforeReport', function (Throwable $e): void {
            ReportApplicationError::fromThrowable($e);
        });

        $this->app->afterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if (! method_exists($handler, 'reportable')) {
                return;
            }

            $handler->reportable(function (Throwable $e): void {
                ReportApplicationError::fromThrowable($e);
            });
        });
    }
}
