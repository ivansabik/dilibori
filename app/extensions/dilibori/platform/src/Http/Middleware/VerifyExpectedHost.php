<?php

declare(strict_types=1);

namespace Dilibori\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Host header must match EXPECTED_HOST when set by the control plane.
 * Spoofed X-Tenant-Id headers are ignored for database selection.
 */
class VerifyExpectedHost
{
    public function handle(Request $request, Closure $next): mixed
    {
        $expected = getenv('EXPECTED_HOST') ?: '';
        $override = storage_path('expected-host');
        if (is_readable($override)) {
            $fromFile = strtolower(trim((string) file_get_contents($override)));
            if ($fromFile !== '' && preg_match('/^[a-z0-9.-]+$/', $fromFile)) {
                $expected = $fromFile;
            }
        }
        if ($expected !== '') {
            $host = strtolower((string) $request->getHost());
            if ($host !== strtolower($expected)) {
                abort(421, 'Misdirected request');
            }
        }

        $host = strtolower((string) $request->getHost());
        $appUrl = (string) config('app.url');
        $port = parse_url($appUrl, PHP_URL_PORT);
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: $request->getScheme();
        $root = $scheme.'://'.$host.($port ? ':'.$port : '');
        config(['app.url' => $root]);
        URL::forceRootUrl($root);

        // Never trust client-supplied tenant identity for data access.
        $request->headers->remove('X-Tenant-Id');
        $request->headers->remove('X-Deployment-Id');

        $tenantId = getenv('TENANT_ID') ?: '';
        if ($tenantId !== '') {
            $request->attributes->set('dilibori.tenant_id', $tenantId);
            $request->attributes->set('dilibori.deployment_id', getenv('DEPLOYMENT_ID') ?: '');
        }

        return $next($request);
    }
}
