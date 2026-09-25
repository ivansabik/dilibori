<?php

declare(strict_types=1);

namespace Dilibori\Platform\Health;

use Igniter\System\Health\WebServerSecurityProbes;
use Override;

/**
 * Probe via loopback Apache when EXPECTED_HOST is set.
 *
 * @see TenantPhpExecutionProbe
 */
class TenantWebServerSecurityProbes extends WebServerSecurityProbes
{
    use ProbesViaLoopback;

    /**
     * @return array{status: 'secured'|'vulnerable'|'unverified', summary: string}
     */
    #[Override]
    protected function probeHttps(): array
    {
        $appUrl = (string) config('app.url');
        if (
            str_starts_with($appUrl, 'http://')
            && str_contains($appUrl, '.localhost')
        ) {
            // Platform local ingress is HTTP-only; TI marks production http as failed.
            return $this->secured('Local HTTP ingress (*.localhost)');
        }

        return parent::probeHttps();
    }
}
