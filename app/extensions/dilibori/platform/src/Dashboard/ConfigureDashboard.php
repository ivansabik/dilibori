<?php

declare(strict_types=1);

namespace Dilibori\Platform\Dashboard;

use Igniter\Admin\Http\Controllers\Dashboard;
use Igniter\User\Facades\AdminAuth;
use Igniter\User\Models\UserPreference;
use Illuminate\Support\Facades\Event;

/**
 * White-label admin dashboard: no News feed, Charts full width.
 */
final class ConfigureDashboard
{
    private const USER_PREFS_KEY = 'admin_dashboardwidgets_dashboard';

    private const SYSTEM_PREFS_KEY = 'admin_dashboardwidgets_default_dashboard';

    public static function boot(): void
    {
        Dashboard::extend(function (Dashboard $controller): void {
            $controller->containerConfig['defaultWidgets'] = self::defaultWidgets();
        });

        Event::listen('admin.controller.beforeInit', function (mixed $controller): void {
            // After FormServiceProvider has registered widgets (admin only).
            HideNewsWidget::unregister();

            if (! $controller instanceof Dashboard) {
                return;
            }

            self::migratePrefs();
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function defaultWidgets(): array
    {
        return [
            'onboarding' => [
                'priority' => 1,
                'width' => '12',
            ],
            'reports' => [
                'widget' => 'charts',
                'priority' => 10,
                'width' => '12',
            ],
            'order_stats' => [
                'widget' => 'stats',
                'priority' => 20,
                'card' => 'sale',
                'width' => '4',
            ],
            'reservation_stats' => [
                'widget' => 'stats',
                'priority' => 20,
                'card' => 'lost_sale',
                'width' => '4',
            ],
            'customer_stats' => [
                'widget' => 'stats',
                'priority' => 20,
                'card' => 'cash_payment',
                'width' => '4',
            ],
        ];
    }

    private static function migratePrefs(): void
    {
        // Use findRecord, not get(): get($key) with no row caches null and
        // later get($key, $defaults) ignores defaults (static::$cache hit).
        if ($user = AdminAuth::user()) {
            $record = UserPreference::findRecord(self::USER_PREFS_KEY, $user);
            if ($record && is_array($record->value) && self::containsNews($record->value)) {
                UserPreference::onUser($user)->set(
                    self::USER_PREFS_KEY,
                    self::stripNewsAndWidenCharts($record->value),
                );
            }
        }

        $systemWidgets = params(self::SYSTEM_PREFS_KEY);
        if (is_array($systemWidgets) && self::containsNews($systemWidgets)) {
            setting()->setPref(self::SYSTEM_PREFS_KEY, self::stripNewsAndWidenCharts($systemWidgets));
        }
    }

    /**
     * @param  array<string, mixed>  $widgets
     */
    private static function containsNews(array $widgets): bool
    {
        foreach ($widgets as $alias => $info) {
            $code = is_array($info) ? ($info['widget'] ?? $alias) : $alias;
            if ($code === 'news' || $alias === 'news') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $widgets
     * @return array<string, mixed>
     */
    private static function stripNewsAndWidenCharts(array $widgets): array
    {
        foreach ($widgets as $alias => $info) {
            $code = is_array($info) ? ($info['widget'] ?? $alias) : $alias;
            if ($code === 'news' || $alias === 'news') {
                unset($widgets[$alias]);
            }
        }

        if (isset($widgets['reports']) && is_array($widgets['reports'])) {
            $widgets['reports']['width'] = '12';
        }

        return $widgets;
    }
}
