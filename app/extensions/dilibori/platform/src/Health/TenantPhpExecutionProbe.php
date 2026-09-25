<?php

declare(strict_types=1);

namespace Dilibori\Platform\Health;

use Igniter\System\Health\PhpExecutionProbe;

/**
 * Probe via loopback Apache when EXPECTED_HOST is set.
 *
 * APP_URL points at host ingress (often *.localhost:8088). Inside the
 * container that name is loopback and the ingress port is closed, so TI's
 * default probes always fail open as "unverified".
 */
class TenantPhpExecutionProbe extends PhpExecutionProbe
{
    use ProbesViaLoopback;
}
