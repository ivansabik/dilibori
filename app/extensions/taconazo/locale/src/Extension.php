<?php

declare(strict_types=1);

namespace Taconazo\Locale;

use Igniter\System\Classes\BaseExtension;
use Igniter\System\Models\Language;
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
