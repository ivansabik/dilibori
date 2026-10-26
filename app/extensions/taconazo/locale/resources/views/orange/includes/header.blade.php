@php($headerAccent = $theme->{'header-accent'} ?? setting('header_accent'))
@php($colorPrimary = $theme->{'color-primary'} ?? null)
{{-- Chile accent when set; else primary. Concrete / trompo chrome for Taconazo. --}}
@php($brandChrome = $headerAccent ?: $colorPrimary ?: '#C43C2C')
@php($logoHeight = $theme->logo_height ?: '72px')
@php($footerOnBrand = (bool) $headerAccent)
@php($footerFg = '#FFF')
@if($footerOnBrand && is_string($headerAccent))
    @php($hex = ltrim($headerAccent, '#'))
    @if(strlen($hex) === 6)
        @php($lum = 0.299 * hexdec(substr($hex, 0, 2)) + 0.587 * hexdec(substr($hex, 2, 2)) + 0.114 * hexdec(substr($hex, 4, 2)))
        @php($footerFg = $lum < 128 ? '#FFF' : '#111')
    @endif
@endif
<style>
    @import url('https://fonts.googleapis.com/css2?family=Jost:wght@400;500;700;800&display=swap');
    :root {
        --tz-ink: #111111;
        --tz-paper: #FAFAF8;
        --tz-concrete: #E8E6E2;
        --tz-steel: #2A2A2A;
        --tz-chile: {{ $brandChrome }};
        --tz-gold: #D4A017;
        --tz-open: #2F6B3A;
        --tz-stone: #6B6560;
    }
    body {
        font-family: Futura, "Futura PT", "Century Gothic", Jost, sans-serif;
        font-weight: 400;
        color: var(--tz-ink);
        background-color: var(--tz-paper);
    }
    .navbar-top,
    .footer,
    .btn,
    .navbar-nav .nav-link,
    .footer-title,
    .text-logo,
    h1, h2, h3, h4, h5, h6,
    .tz-hero-title,
    .tz-favoritos-title,
    .menu-name,
    .tz-order-cta {
        font-family: Futura, "Futura PT", "Futura Display", "Century Gothic", Jost, sans-serif;
    }
    h1, h2, h3, h4, h5, h6,
    .btn,
    .navbar-nav .nav-link,
    .footer-title,
    .text-logo {
        font-weight: 800;
    }
    .navbar-top {
        background-color: var(--tz-paper) !important;
        border-bottom: 2px solid var(--tz-chile);
    }
    .btn-primary,
    .btn-primary:hover,
    .btn-primary:focus,
    .btn-primary:active {
        background-color: var(--tz-chile) !important;
        border-color: var(--tz-chile) !important;
        color: #fff !important;
    }
    /* Order-first nav: first top-level item is View Menu */
    .navbar-top .navbar-nav > .nav-item:first-child > .nav-link {
        background-color: var(--tz-chile);
        color: #fff !important;
        border-radius: 999px;
        padding-left: 1.15rem;
        padding-right: 1.15rem;
        margin-right: 0.35rem;
    }
    .navbar-top .navbar-nav > .nav-item:nth-child(3) > .nav-link,
    .navbar-top .navbar-nav > .nav-item:nth-child(4) > .nav-link {
        font-weight: 500;
        color: var(--tz-stone) !important;
    }
    .tz-price,
    .menu-price {
        color: var(--tz-chile);
        font-weight: 700;
    }
    .tz-menu-desc,
    .menu-desc {
        font-weight: 400;
        color: var(--tz-stone) !important;
        font-size: 0.95rem;
    }
    .tz-menu-item {
        background: #fff;
        border: 0;
        border-bottom: 1px solid var(--tz-concrete);
        border-radius: 0;
        box-shadow: none;
        transition: background-color 0.12s ease;
    }
    .tz-menu-item-available:hover {
        background-color: #fff;
    }
    .tz-menu-item-available:active .tz-thumb-add,
    .tz-add-btn:active {
        transform: scale(0.92);
        background-color: var(--tz-chile);
        color: #fff;
        border-color: var(--tz-chile);
    }
    .tz-menu-thumb {
        width: 180px;
        height: 180px;
        object-fit: cover;
        box-shadow: 0 2px 8px rgba(17, 17, 17, 0.08);
    }
    @media (max-width: 575.98px) {
        .tz-menu-thumb { width: 112px; height: 112px; }
    }
    .tz-thumb-add {
        position: absolute;
        right: 6px;
        bottom: 6px;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: var(--tz-chile);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        box-shadow: 0 1px 4px rgba(0,0,0,.2);
        transition: transform 0.12s ease;
    }
    .tz-badge-special {
        display: inline-block;
        background: var(--tz-gold);
        color: #111;
        font-size: 0.7rem;
        font-weight: 800;
        padding: 0.15rem 0.45rem;
        border-radius: 4px;
        white-space: nowrap;
    }
    .tz-local-bar,
    .tz-category-rail {
        background: var(--tz-concrete);
        z-index: 1020;
    }
    .tz-category-rail { top: 0; }
    .tz-local-header .h5 { font-weight: 800; }
    .tz-open-pill {
        color: var(--tz-open);
        font-weight: 700;
    }
    .tz-fulfillment-chip {
        background: #fff;
        border: 1px solid #d5d2cd;
        border-radius: 8px;
        padding: 0.5rem 0.75rem;
        font-size: 0.9rem;
        white-space: nowrap;
    }
    .tz-breakfast-callout {
        background: #fff8e8;
        color: var(--tz-ink);
        font-weight: 600;
    }
    .tz-promo-rail { background: var(--tz-paper); }
    .tz-promo {
        display: block;
        padding: 0.85rem 1rem;
        border-radius: 8px;
        color: var(--tz-ink);
        border: 1px solid var(--tz-concrete);
        background: #fff;
    }
    .tz-promo-pastor {
        border-left: 4px solid var(--tz-gold);
    }
    .tz-promo-welcome {
        border-left: 4px solid var(--tz-chile);
    }
    .tz-promo-code {
        background: var(--tz-concrete);
        padding: 0.1rem 0.35rem;
        border-radius: 3px;
        font-weight: 700;
        color: var(--tz-chile);
    }
    .tz-favoritos { background: var(--tz-steel); color: #fff; }
    .tz-favoritos-title { color: #fff; font-weight: 800; }
    .tz-favorito-card {
        background: var(--tz-concrete);
        border-radius: 12px;
        overflow: hidden;
        transition: transform 0.15s ease;
    }
    .tz-favorito-card:hover { transform: translateY(-2px); }
    .tz-favorito-img {
        aspect-ratio: 4 / 3;
        object-fit: cover;
        display: block;
    }
    .tz-favorito-body { background: #fff; color: var(--tz-ink); }
    .tz-favorito-price { color: var(--tz-chile); font-weight: 700; }
    .tz-hero-title { font-weight: 800; }
    .tz-hero-sub { font-weight: 400; }
    .tz-hero-band { background: var(--tz-paper); }
    .tz-upsell-chip {
        border: 1px solid var(--tz-concrete);
        background: var(--tz-paper);
        border-radius: 999px;
    }
    .tz-upsell-chip:hover {
        border-color: var(--tz-chile);
        color: var(--tz-chile);
    }
    .buttons-mobile,
    .buttons-mobile .btn,
    a[href*="cart"].btn,
    .fixed-bottom .btn-primary {
        background-color: var(--tz-steel) !important;
        border-color: var(--tz-steel) !important;
    }
    #euCookieBanner { font-size: 0.85rem; }
    #euCookieBanner .container { padding-top: 0.5rem; padding-bottom: 0.5rem; }
    @if($footerOnBrand)
    .footer {
        background-color: var(--tz-steel) !important;
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
        opacity: 0.85;
    }
    .footer .footer-links i { color: {{ $footerFg }} !important; }
    .footer hr {
        border-color: {{ $footerFg }};
        opacity: 0.35;
    }
    @endif
    body:has(#euCookieBanner[style*="display: block"]) .footer,
    body:has(#euCookieBanner:not([style*="display: none"])) .footer {
        padding-bottom: 4rem;
    }
    /* Slider: food-first stage */
    .carousel-item img {
        object-fit: cover;
        max-height: 52vh;
        min-height: 220px;
    }
    @media (min-width: 768px) {
        .carousel-item img { max-height: 56vh; min-height: 320px; }
    }
</style>
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
