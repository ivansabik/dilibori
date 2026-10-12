@php($headerAccent = $theme->{'header-accent'} ?? setting('header_accent'))
@php($colorPrimary = $theme->{'color-primary'} ?? null)
{{-- Fonts/buttons follow brand color even when header_accent is unset (Taconazo). --}}
@php($brandChrome = $headerAccent ?: $colorPrimary)
@php($logoHeight = $theme->logo_height ?: '72px')
@php($footerOnBrand = (bool) $headerAccent)
@php($footerFg = '#000')
@if($footerOnBrand && is_string($headerAccent))
    @php($hex = ltrim($headerAccent, '#'))
    @if(strlen($hex) === 6)
        @php($lum = 0.299 * hexdec(substr($hex, 0, 2)) + 0.587 * hexdec(substr($hex, 2, 2)) + 0.114 * hexdec(substr($hex, 4, 2)))
        @php($footerFg = $lum < 128 ? '#FFF' : '#000')
    @endif
@endif
@if($brandChrome)
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Jost:wght@700;800&display=swap');
        body,
        .navbar-top,
        .footer {
            font-family: Futura, "Futura PT", "Century Gothic", Jost, sans-serif;
            font-weight: 700;
        }
        h1, h2, h3, h4, h5, h6,
        .btn,
        .navbar-nav .nav-link,
        .footer-title,
        .text-logo {
            font-family: Futura, "Futura PT", "Futura Display", "Century Gothic", Jost, sans-serif;
            font-weight: 800;
        }
        .btn-primary,
        .btn-primary:hover,
        .btn-primary:focus {
            background-color: {{ $brandChrome }};
            border-color: {{ $brandChrome }};
        }
        @if($footerOnBrand)
        .navbar-top { border-bottom: 3px solid {{ $headerAccent }}; }
        .footer {
            background-color: {{ $headerAccent }} !important;
            color: {{ $footerFg }} !important;
        }
        .footer a,
        .footer h5,
        .footer h6,
        .footer p,
        .footer span,
        .footer li,
        .footer .footer-title,
        .footer .text-muted {
            color: {{ $footerFg }} !important;
        }
        .footer a:hover,
        .footer a:focus {
            color: {{ $footerFg }} !important;
        }
        .footer .footer-links i {
            color: {{ $footerFg }} !important;
        }
        .footer hr {
            border-color: {{ $footerFg }};
            opacity: 0.35;
        }
        @endif
        /* Cookie bar sits on the footer; keep site footer readable above it. */
        body:has(#euCookieBanner[style*="display: block"]) .footer,
        body:has(#euCookieBanner:not([style*="display: none"])) .footer {
            padding-bottom: 6rem;
        }
    </style>
@else
    <style>
        body:has(#euCookieBanner[style*="display: block"]) .footer,
        body:has(#euCookieBanner:not([style*="display: none"])) .footer {
            padding-bottom: 6rem;
        }
    </style>
@endif
<nav class="navbar navbar-light navbar-top navbar-expand-md py-sm-2 py-md-0 position-relative" style="z-index: 1040; --logo-height: {{ $logoHeight }};">
    <div class="container">
        <a class="navbar-brand" href="{{ page_url('home') }}">
            @php($logoPath = $theme->logo_image ?: (setting('site_logo') !== 'no_photo.png' ? setting('site_logo') : null))
            @if($logoPath)
                <img
                    class="img-logo"
                    alt="{{ setting('site_name') }}"
                    src="/storage/media/uploads/{{ basename($logoPath) }}"
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
            aria-label="{{ lang('taconazo.locale::default.aria_toggle_navigation') }}"
        ><span class="navbar-toggler-icon"></span></button>

        <div class="justify-content-end align-items-center collapse navbar-collapse" id="navbarMainHeader">
            <x-igniter-orange::nav code="main-menu"/>
            @include('taconazo.locale::partials.locale-picker')
        </div>
    </div>
</nav>
