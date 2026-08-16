<?php

declare(strict_types=1);

namespace Taconazo\Locale\Livewire;

use Igniter\System\Models\Language;
use Livewire\Component;

class LocalePicker extends Component
{
    public function switchLocale(string $locale): mixed
    {
        $localization = resolve('translator.localization');
        if (!$localization->isValid($locale)) {
            return null;
        }

        $localization->setSessionLocale($locale);
        $localization->setLocale($locale);

        $url = request()->header('Referer') ?: url('/');

        return $this->redirect($url, navigate: false);
    }

    public function render()
    {
        $locales = Language::listSupported();
        $active = resolve('translator.localization')->getLocale();

        return view('taconazo.locale::livewire.locale-picker', [
            'locales' => $locales,
            'activeLocale' => $active,
        ]);
    }
}
