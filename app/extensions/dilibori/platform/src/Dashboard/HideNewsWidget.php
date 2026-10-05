<?php

declare(strict_types=1);

namespace Dilibori\Platform\Dashboard;

use Igniter\Admin\Classes\Widgets;
use Igniter\Flame\Support\Facades\Igniter;
use Igniter\System\DashboardWidgets\News;
use ReflectionClass;

/**
 * Removes the stock TastyIgniter News RSS widget from the admin registry.
 *
 * Must run only in admin after FormServiceProvider has registered widgets.
 * Calling listDashboardWidgets() outside admin freezes an empty registry
 * (core skips registration when !Igniter::runningInAdmin()).
 */
final class HideNewsWidget
{
    private static bool $done = false;

    public static function unregister(): void
    {
        if (self::$done || ! Igniter::runningInAdmin()) {
            return;
        }

        $manager = resolve(Widgets::class);
        $manager->listDashboardWidgets();

        $reflection = new ReflectionClass($manager);

        $widgetsProperty = $reflection->getProperty('dashboardWidgets');
        $widgets = $widgetsProperty->getValue($manager);
        if (is_array($widgets)) {
            unset($widgets[News::class]);
            $widgetsProperty->setValue($manager, $widgets);
        }

        $hintsProperty = $reflection->getProperty('dashboardWidgetHints');
        $hints = $hintsProperty->getValue($manager);
        if (is_array($hints)) {
            unset($hints['news']);
            $hintsProperty->setValue($manager, $hints);
        }

        self::$done = true;
    }
}
