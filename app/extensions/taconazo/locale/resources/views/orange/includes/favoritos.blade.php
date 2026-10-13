@php
    use Igniter\Cart\Models\Menu;
    use Igniter\Local\Models\Location;

    $defaultLocation = null;
    try {
        $defaultLocation = Location::getDefault();
    } catch (\Throwable) {
    }
    if (!$defaultLocation) {
        $defaultLocation = Location::query()->where('location_status', 1)->orderBy('location_id')->first();
    }

    $favoritoIds = setting('taconazo_favoritos_ids');
    if (is_string($favoritoIds) && $favoritoIds !== '') {
        $favoritoIds = array_filter(array_map('intval', explode(',', $favoritoIds)));
    }
    if (!is_array($favoritoIds) || $favoritoIds === []) {
        $favoritoIds = [];
    }

    $query = Menu::query()->with(['media', 'locations'])->where('menu_status', 1);
    if ($favoritoIds !== []) {
        $query->whereIn('menu_id', $favoritoIds);
    } else {
        $query->where(function ($q) {
            $q->where('menu_name', 'like', '%Plato Taconazo%')
                ->orWhere('menu_name', 'like', '%mix%')
                ->orWhere('menu_name', 'like', '%Pastor%');
        });
    }
    if ($defaultLocation) {
        $locId = $defaultLocation->getKey();
        $query->whereHas('locations', fn ($q) => $q->where('locations.location_id', $locId));
    }
    $favoritos = $query->orderBy('menu_priority')->take(3)->get();
    $menusBase = $defaultLocation ? url($defaultLocation->permalink_slug.'/menus') : url('/locations');
@endphp
@if($favoritos->isNotEmpty())
    <div class="tz-favoritos py-5">
        <div class="container">
            <h2 class="tz-favoritos-title text-center mb-4">@lang('taconazo.locale::default.favoritos_title')</h2>
            <div class="row g-3 justify-content-center">
                @foreach($favoritos as $menu)
                    @php
                        $thumbSrc = orange_media_src((string) $menu->getThumb(['width' => 480, 'height' => 360]));
                        $href = $menusBase.'#menu'.$menu->getKey();
                        $special = method_exists($menu, 'specialIsActive') && $menu->specialIsActive();
                    @endphp
                    <div class="col-12 col-sm-6 col-lg-4">
                        <a class="tz-favorito-card text-decoration-none text-reset d-block h-100" href="{{ $href }}">
                            @if($thumbSrc !== '' && !str_starts_with($thumbSrc, 'data:'))
                                <img
                                    class="tz-favorito-img w-100"
                                    src="{{ $thumbSrc }}"
                                    alt="{{ $menu->menu_name }}"
                                    loading="lazy"
                                />
                            @endif
                            <div class="tz-favorito-body p-3">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <h3 class="h5 mb-1">{{ $menu->menu_name }}</h3>
                                    @if($special)
                                        <span class="tz-badge-special">@lang('taconazo.locale::default.badge_special')</span>
                                    @endif
                                </div>
                                <p class="tz-favorito-price mb-0">
                                    {!! currency_format($menu->menu_price) !!}
                                </p>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
