<?php

declare(strict_types=1);

namespace Dilibori\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Platform policy: tenant admins cannot install executable code.
 */
class BlockTenantCodeInstall
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! filter_var(getenv('FORBID_MARKETPLACE_INSTALL') ?: 'true', FILTER_VALIDATE_BOOLEAN)) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        $blocked = [
            'extensions/install',
            'extensions/upload',
            'themes/upload',
            'updates/apply',
            'marketplace',
        ];

        foreach ($blocked as $needle) {
            if (str_contains($path, $needle)) {
                abort(403, 'Platform policy: tenant admins cannot install code.');
            }
        }

        return $next($request);
    }
}
