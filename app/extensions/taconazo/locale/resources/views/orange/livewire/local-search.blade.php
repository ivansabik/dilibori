<div>
    <div class="text-center tz-local-search">
        @php
            use Igniter\Local\Models\Location;

            $heroLocale = app()->getLocale();
            $heroTitle = setting("hero_title_{$heroLocale}")
                ?: setting('hero_title')
                ?: lang('taconazo.locale::default.hero_title');
            $heroSubtitle = setting("hero_subtitle_{$heroLocale}")
                ?: setting('hero_subtitle')
                ?: lang('taconazo.locale::default.hero_subtitle');

            $locations = Location::query()
                ->where('location_status', 1)
                ->orderByDesc('location_id')
                ->get();
            try {
                $default = Location::getDefault();
                if ($default) {
                    $locations = $locations->sortByDesc(
                        fn ($loc) => (int) ($loc->getKey() === $default->getKey())
                    )->values();
                }
            } catch (\Throwable) {
            }
        @endphp
        <h2
            class="tz-hero-title mb-2"
            @if(setting('hero_heading_color')) style="color: {{ setting('hero_heading_color') }}" @endif
        >{{ $heroTitle }}</h2>
        <p class="tz-hero-sub text-muted mb-4">{{ $heroSubtitle }}</p>

        @if($locations->count() > 1)
            <div class="d-flex flex-column flex-sm-row gap-3 justify-content-center">
                @foreach($locations as $location)
                    @php
                        $isMx = str_contains(strtolower((string) $location->location_name), 'juárez')
                            || str_contains(strtolower((string) $location->location_name), 'juarez')
                            || str_contains(strtolower((string) $location->permalink_slug), 'tecnologico');
                        $label = $isMx
                            ? lang('taconazo.locale::default.cta_juarez')
                            : lang('taconazo.locale::default.cta_el_paso');
                    @endphp
                    <a
                        class="btn btn-primary btn-lg fw-bold px-4 tz-order-cta"
                        href="{{ url($location->permalink_slug.'/menus') }}"
                    >{{ $label }}</a>
                @endforeach
            </div>
            <p class="small text-muted mt-3 mb-0">@lang('taconazo.locale::default.hero_branch_hint')</p>
        @else
            @php
                $only = $locations->first();
                $menusUrl = $only ? url($only->permalink_slug.'/menus') : url('/locations');
            @endphp
            <a class="btn btn-primary btn-lg fw-bold px-5 tz-order-cta" href="{{ $menusUrl }}">
                @lang('igniter.orange::default.menu_menu')
            </a>
        @endif
    </div>
</div>
