@php
    $privacyPage = \Igniter\Pages\Models\Page::find($theme->gdpr_more_info_link);
    $isEs = str_starts_with((string) app()->getLocale(), 'es');
    $cookieMessage = $isEs
        ? lang('igniter.orange::default.gdpr.cookie_message')
        : $theme->gdpr_cookie_message;
    $moreInfoText = $isEs
        ? lang('igniter.orange::default.gdpr.more_info')
        : $theme->gdpr_more_info_text;
    $acceptText = $isEs
        ? lang('igniter.orange::default.gdpr.accept')
        : $theme->gdpr_accept_text;
    $headerAccent = $theme->{'header-accent'} ?? setting('header_accent');
    $bannerBg = $headerAccent ? '#000' : ($theme->gdpr_background_color ?: '#000');
    $bannerFg = $headerAccent ? '#fff' : ($theme->gdpr_text_color ?: '#FFF');
@endphp
<div
    id="euCookieBanner"
    data-control="cookie-banner"
    data-active="{{ $theme->enable_gdpr }}"
    style="display:none;"
>
    <div
        style="background-color: {{ $bannerBg }}; color: {{ $bannerFg }};"
    >
        <div class="container">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <p id="eu-cookie-message" class="mb-0 flex-grow-1 pe-2" style="color: {{ $bannerFg }};">
                    <span>{{ $cookieMessage }}</span>
                    <a
                        href="{{ page_url($privacyPage ? $privacyPage->permalink_slug : 'policy') }}"
                        style="color: {{ $bannerFg }}; text-decoration: underline;"
                    >{{ $moreInfoText }}</a>
                </p>
                <a
                    id="eu-cookie-action"
                    class="btn ms-md-auto{{ $headerAccent ? '' : ' btn-secondary' }}"
                    href="javascript:void(0);"
                    @if($headerAccent)
                        style="background-color: #fff; border-color: #fff; color: #000;"
                    @endif
                >{{ $acceptText }}</a>
            </div>
        </div>
    </div>
</div>
