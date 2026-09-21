<?php

declare(strict_types=1);

namespace Dilibori\Platform;

use Dilibori\Platform\Http\Middleware\BlockTenantCodeInstall;
use Dilibori\Platform\Http\Middleware\VerifyExpectedHost;
use Igniter\System\Classes\BaseExtension;
use Illuminate\Support\Facades\URL;
use Override;

class Extension extends BaseExtension
{
    #[Override]
    public function register(): void
    {
        $this->app['router']->pushMiddlewareToGroup('web', VerifyExpectedHost::class);
        $this->app['router']->pushMiddlewareToGroup('igniter', VerifyExpectedHost::class);
        $this->app['router']->pushMiddlewareToGroup('web', BlockTenantCodeInstall::class);
        $this->app['router']->pushMiddlewareToGroup('igniter', BlockTenantCodeInstall::class);
    }

    #[Override]
    public function boot(): void
    {
        $path = storage_path('expected-host');
        if (!is_readable($path)) {
            return;
        }

        $host = strtolower(trim((string) file_get_contents($path)));
        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            return;
        }

        $parts = parse_url((string) (getenv('APP_URL') ?: config('app.url'))) ?: [];
        $scheme = $parts['scheme'] ?? 'http';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $url = $scheme.'://'.$host.$port;
        config(['app.url' => $url]);
        URL::forceRootUrl($url);
    }
}
