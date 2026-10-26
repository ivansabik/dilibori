<?php

declare(strict_types=1);

namespace Taconazo\Locale;

use Igniter\System\Classes\BaseExtension;
use Igniter\System\Models\Language;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Override;
use Taconazo\Locale\Http\Middleware\SetLocationCurrency;
use Taconazo\Locale\Livewire\LocalePicker;

class Extension extends BaseExtension
{
    #[Override]
    public function register(): void
    {
        require_once __DIR__.'/helpers.php';

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'taconazo.locale');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'taconazo.locale');
        $this->app['router']->pushMiddlewareToGroup('web', SetLocationCurrency::class);
    }

    #[Override]
    public function boot(): void
    {
        $langPath = dirname(__DIR__).'/lang';
        if (is_dir($langPath) && method_exists(app('translation.loader'), 'addPath')) {
            app('translation.loader')->addPath($langPath);
        }

        View::prependNamespace('igniter-orange', __DIR__.'/../resources/views/orange');
        // Pagic loads theme pages from the orange package path, not the view
        // namespace — publish _pages overrides so home/menus ships with the extension.
        $this->publishOrangePages();
        Livewire::component('taconazo.locale-picker', LocalePicker::class);

        Route::middleware('web')->get('/locale/{locale}', function (string $locale) {
            $supported = array_keys(Language::listSupported());
            if (in_array($locale, $supported, true)) {
                config(['localization.supportedLocales' => $supported]);
                $localization = resolve('translator.localization');
                $localization->setSessionLocale($locale);
                $localization->setLocale($locale);
            }

            $fallback = url('/');
            $previous = url()->previous($fallback);

            return redirect()->to($previous === url()->current() ? $fallback : $previous);
        })->name('taconazo.locale.switch');
    }

    /**
     * Copy `_pages` Blade overrides into ti-theme-orange so Pagic serves them.
     */
    private function publishOrangePages(): void
    {
        $from = __DIR__.'/../resources/views/orange/_pages';
        $to = base_path('vendor/tastyigniter/ti-theme-orange/resources/views/_pages');
        if (! is_dir($from) || ! is_dir($to)) {
            return;
        }

        try {
            foreach (File::allFiles($from) as $file) {
                $relative = $file->getRelativePathname();
                $dest = $to.DIRECTORY_SEPARATOR.$relative;
                $destDir = dirname($dest);
                if (! is_dir($destDir)) {
                    File::makeDirectory($destDir, 0755, true);
                }
                if (! is_file($dest) || md5_file($file->getPathname()) !== md5_file($dest)) {
                    File::copy($file->getPathname(), $dest);
                }
            }
        } catch (\Throwable) {
            // Vendor may be read-only in some deploys; views namespace still covers partials.
        }
    }

    #[Override]
    public function registerComponents(): array
    {
        return [];
    }

    #[Override]
    public function registerPermissions(): array
    {
        return [];
    }
}
