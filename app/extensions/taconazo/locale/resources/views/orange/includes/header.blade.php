<nav class="navbar navbar-light navbar-top navbar-expand-md py-sm-2 py-md-0 position-relative" style="z-index: 1040; --logo-height: 72px;">
    <div class="container">
        <a class="navbar-brand" href="{{ page_url('home') }}">
            @if($theme->logo_image)
                <img
                    class="img-logo"
                    alt="{{ setting('site_name') }}"
                    src="{{ media_url($theme->logo_image) }}"
                />
            @elseif($theme->logo_text)
                <span class="text-logo">{{ $theme->logo_text }}</span>
            @else
                <img
                    class="img-logo"
                    alt="{{ $site_name }}"
                    src="{{ $site_logo !== 'no_photo.png'
                        ? media_thumb($site_logo)
                        : asset('vendor/igniter-orange/images/favicon.ico') }}"
                />
            @endif
        </a>
        <button
            class="navbar-toggler border-0"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navbarMainHeader"
            aria-controls="navbarMainHeader"
            aria-expanded="false"
            aria-label="Toggle navigation"
        ><span class="navbar-toggler-icon"></span></button>

        <div class="justify-content-end align-items-center collapse navbar-collapse" id="navbarMainHeader">
            <x-igniter-orange::nav code="main-menu"/>
            @include('taconazo.locale::partials.locale-picker')
        </div>
    </div>
</nav>
