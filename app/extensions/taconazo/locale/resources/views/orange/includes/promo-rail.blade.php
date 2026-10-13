@php
    $locale = app()->getLocale();
    $isEs = str_starts_with((string) $locale, 'es');
@endphp
<div class="d-flex flex-column flex-md-row gap-3 justify-content-center align-items-stretch">
    <a
        class="tz-promo tz-promo-pastor text-decoration-none flex-fill"
        href="{{ url('el-taconazo-alameda-ave/menus') }}"
    >
        <strong>@lang('taconazo.locale::default.promo_pastor_title')</strong>
        <span class="d-block small mt-1">@lang('taconazo.locale::default.promo_pastor_body')</span>
    </a>
    <a
        class="tz-promo tz-promo-welcome text-decoration-none flex-fill"
        href="{{ url('el-taconazo-alameda-ave/menus') }}"
    >
        <strong>@lang('taconazo.locale::default.promo_welcome_title')</strong>
        <span class="d-block small mt-1">
            @lang('taconazo.locale::default.promo_welcome_body')
            <code class="tz-promo-code">{{ $isEs ? 'BIENVENIDO' : 'BIENVENIDO' }}</code>
        </span>
    </a>
</div>
