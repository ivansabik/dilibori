<?php

declare(strict_types=1);

namespace Dilibori\Platform;

use Dilibori\Platform\Dashboard\ConfigureDashboard;
use Igniter\System\Classes\BaseExtension;
use Override;

class Extension extends BaseExtension
{
    #[Override]
    public function boot(): void
    {
        config(['igniter-system.sendPoweredByHeader' => false]);
        ConfigureDashboard::boot();
    }
}
