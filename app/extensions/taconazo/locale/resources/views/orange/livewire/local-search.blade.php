<div>
    <div class="text-center">
        @php
            $heroLocale = app()->getLocale();
            $heroTitle = setting("hero_title_{$heroLocale}")
                ?: setting('hero_title')
                ?: lang('taconazo.locale::default.hero_title');
            $heroSubtitle = setting("hero_subtitle_{$heroLocale}")
                ?: setting('hero_subtitle')
                ?: lang('taconazo.locale::default.hero_subtitle');
            $defaultLocation = \Igniter\Local\Models\Location::query()->where('location_status', 1)->orderByDesc('location_id')->first();
            // Prefer the marked default when the column exists on this install.
            try {
                $defaultLocation = \Igniter\Local\Models\Location::getDefault() ?: $defaultLocation;
            } catch (\Throwable) {
            }
            $menusUrl = $defaultLocation ? url($defaultLocation->permalink_slug.'/menus') : url('/locations');
        @endphp
        <h2 class="mb-3" @if(setting('hero_heading_color')) style="color: {{ setting('hero_heading_color') }}" @endif>{{ $heroTitle }}</h2>
        <p class="text-muted mb-4">{{ $heroSubtitle }}</p>
        <a
            class="btn btn-primary btn-lg fw-bold px-5"
            href="{{ $menusUrl }}"
        >@lang('igniter.orange::default.menu_menu')</a>
    </div>
</div>
