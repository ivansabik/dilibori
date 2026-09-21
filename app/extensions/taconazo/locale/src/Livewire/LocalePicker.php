<?php

declare(strict_types=1);

namespace Taconazo\Locale\Livewire;

use Igniter\System\Models\Language;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class LocalePicker extends Component
{
    public function switchLocale(string $locale): mixed
    {
        if (! $this->shouldShow()) {
            return null;
        }

        $localization = resolve('translator.localization');
        if (! $localization->isValid($locale)) {
            return null;
        }

        $localization->setSessionLocale($locale);
        $localization->setLocale($locale);

        $url = request()->header('Referer') ?: url('/');

        return $this->redirect($url, navigate: false);
    }

    public function render(): View
    {
        $locales = Language::listSupported();
        $active = resolve('translator.localization')->getLocale();

        return view('taconazo.locale::livewire.locale-picker', [
            'locales' => $this->shouldShow() ? $locales : [],
            'activeLocale' => $active,
            'showPicker' => $this->shouldShow(),
        ]);
    }

    protected function shouldShow(): bool
    {
        $locales = Language::listSupported();
        if (count($locales) < 2) {
            return false;
        }

        try {
            $row = DB::table('settings')
                ->where('item', 'dilibori_show_language_picker')
                ->where('sort', 'config')
                ->value('value');
            if ($row === '0' || $row === 0) {
                return false;
            }
        } catch (\Throwable) {
        }

        return true;
    }
}
