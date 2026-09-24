@php($headerAccent = $theme->{'header-accent'} ?? setting('header_accent'))
@php($logoHeight = $theme->logo_height ?: '72px')
@if($headerAccent)
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
        .navbar-top { border-bottom: 3px solid {{ $headerAccent }}; }
        .btn-primary,
        .btn-primary:hover,
        .btn-primary:focus {
            background-color: {{ $headerAccent }};
            border-color: {{ $headerAccent }};
        }
        .footer {
            background-color: {{ $headerAccent }} !important;
            color: #000 !important;
        }
        .footer a,
        .footer h5,
        .footer h6,
        .footer p,
        .footer span,
        .footer li,
        .footer .footer-title,
        .footer .text-muted {
            color: #000 !important;
        }
        .footer a:hover,
        .footer a:focus {
            color: #000 !important;
        }
        .footer .footer-links i {
            color: #000 !important;
        }
        .footer hr {
            border-color: #000;
            opacity: 0.35;
        }
        /* Cookie bar sits on the footer; keep site footer readable above it. */
        body:has(#euCookieBanner[style*="display: block"]) .footer,
        body:has(#euCookieBanner:not([style*="display: none"])) .footer {
            padding-bottom: 6rem;
        }
    </style>
@endif
@unless($headerAccent)
    <style>
        body:has(#euCookieBanner[style*="display: block"]) .footer,
        body:has(#euCookieBanner:not([style*="display: none"])) .footer {
            padding-bottom: 6rem;
        }
    </style>
@endunless
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
