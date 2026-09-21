<div>
    <div class="text-center">
        <h2 class="mb-3" @if(setting('hero_heading_color')) style="color: {{ setting('hero_heading_color') }}" @endif>{{ setting('hero_title') ?: lang('taconazo.locale::default.hero_title') }}</h2>
        <p class="text-muted mb-4">{{ setting('hero_subtitle') ?: lang('taconazo.locale::default.hero_subtitle') }}</p>
        @php
            $defaultLocation = \Igniter\Local\Models\Location::query()->where('location_status', 1)->orderByDesc('location_id')->first();
            // Prefer the marked default when the column exists on this install.
            try {
                $defaultLocation = \Igniter\Local\Models\Location::getDefault() ?: $defaultLocation;
            } catch (\Throwable) {
            }
            $menusUrl = $defaultLocation ? url($defaultLocation->permalink_slug.'/menus') : url('/locations');
        @endphp
        <a
            class="btn btn-primary btn-lg fw-bold px-5"
            href="{{ $menusUrl }}"
        >@lang('igniter.orange::default.menu_menu')</a>
    </div>
</div>
